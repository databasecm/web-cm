<?php

namespace App\Services;

use App\Enums\PurchaseOrderStatus;
use App\Enums\TransactionCategory;
use App\Enums\TransactionType;
use App\Exceptions\PurchaseOrderException;
use App\Models\PoItem;
use App\Models\PurchaseOrder;
use App\Models\Transaction;
use App\Models\User;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;

/**
 * Material purchase-order lifecycle (Fase 6-5). Pure service + guards;
 * authorization is the caller's job (PurchaseOrderPolicy).
 *
 * Flow: draft → ordered → received. The material expense is posted to the cash
 * book ONLY on receive — goods in hand is the realised cash-out, not the order
 * (which may still be cancelled). Receive is idempotent + race-safe
 * (lockForUpdate): a PO received twice never doubles the expense. Received and
 * cancelled POs are final.
 *
 * All money is BigDecimal-exact (ADR-0005); line unit_price is a snapshot taken
 * at PO creation, so a later material price change does not move the PO.
 */
class PurchaseOrderService
{
    /**
     * Recompute each line subtotal (quantity × snapshot unit_price) and the PO
     * total (Σ subtotal), BigDecimal-exact. Called after items change.
     */
    public function recalculate(PurchaseOrder $po): PurchaseOrder
    {
        $total = BigDecimal::zero();

        foreach ($po->items()->get() as $item) {
            /** @var PoItem $item */
            $subtotal = BigDecimal::of((string) $item->quantity)
                ->multipliedBy((string) $item->unit_price)
                ->toScale(2, RoundingMode::HALF_UP);

            if ((string) $item->subtotal !== (string) $subtotal) {
                $item->update(['subtotal' => (string) $subtotal]);
            }

            $total = $total->plus($subtotal);
        }

        $po->update(['total' => (string) $total->toScale(2, RoundingMode::HALF_UP)]);

        return $po->refresh();
    }

    /**
     * Move a draft PO to ordered (sent to supplier). Requires at least one item.
     */
    public function order(PurchaseOrder $po, ?User $by = null): PurchaseOrder
    {
        if ($po->status !== PurchaseOrderStatus::Draft) {
            throw PurchaseOrderException::notOrderable();
        }
        if ($po->items()->count() === 0) {
            throw PurchaseOrderException::empty();
        }

        $this->recalculate($po);
        $po->update(['status' => PurchaseOrderStatus::Ordered, 'ordered_by' => $by?->id ?? $po->ordered_by]);

        return $po->refresh();
    }

    /**
     * Receive an ordered PO: post the material expense (goods in hand) and mark
     * it received. Idempotent + race-safe — a double receive is refused, so the
     * cash book never gets a duplicate material expense.
     */
    public function receive(PurchaseOrder $po, ?User $by = null): Transaction
    {
        return DB::transaction(function () use ($po, $by): Transaction {
            $locked = PurchaseOrder::query()->whereKey($po->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->status === PurchaseOrderStatus::Received) {
                throw PurchaseOrderException::alreadyReceived();
            }
            if ($locked->status !== PurchaseOrderStatus::Ordered) {
                throw PurchaseOrderException::notReceivable();
            }

            $this->recalculate($locked);
            $amount = (string) BigDecimal::of((string) $locked->total)->toScale(2, RoundingMode::HALF_UP);

            $locked->update([
                'status' => PurchaseOrderStatus::Received,
                'received_by' => $by?->id,
                'received_at' => now(),
            ]);

            return Transaction::create([
                'type' => TransactionType::Expense,
                'category' => TransactionCategory::Material,
                'amount' => $amount,
                'reference_type' => Transaction::REF_PO,
                'reference_id' => $locked->id,
                'project_id' => $locked->project_id, // per-project P&L (Fase 6-3b)
                'description' => "Penerimaan PO material {$locked->po_number}",
                'recorded_by' => $by?->id,
                'date' => now()->toDateString(),
            ]);
        });
    }

    /**
     * Cancel a PO before receipt (from draft or ordered). A received or already
     * cancelled PO is final and cannot be cancelled.
     */
    public function cancel(PurchaseOrder $po, ?User $by = null): PurchaseOrder
    {
        if ($po->status->isFinal()) {
            throw PurchaseOrderException::notCancellable();
        }

        $po->update(['status' => PurchaseOrderStatus::Cancelled]);

        return $po->refresh();
    }
}
