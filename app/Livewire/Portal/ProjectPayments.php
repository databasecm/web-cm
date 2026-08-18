<?php

namespace App\Livewire\Portal;

use App\Enums\PaymentScheme;
use App\Exceptions\CheckoutException;
use App\Exceptions\PaymentException;
use App\Models\Installment;
use App\Models\Project;
use App\Services\CheckoutService;
use App\Services\PaymentService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Consumer payments area (Fase portal P-4): pick a scheme, see the installment
 * schedule, raise a VA charge, and watch a term flip to paid.
 *
 * Every money operation goes through the SAME gate + service as the API — there
 * is no payment logic here:
 *  - checkout: Gate::authorize('checkout', $project) → CheckoutService::checkout
 *  - charge:   Gate::authorize('charge', $installment) → PaymentService::createCharge
 *    (idempotent; the payability/§7 state guard lives in the service, not here)
 *
 * The portal NEVER settles: pay() is reached only by the payment webhook. This
 * page merely wire:polls the schedule so a term shows as paid once the webhook
 * has settled it.
 */
#[Layout('components.layouts.portal')]
class ProjectPayments extends Component
{
    public Project $project;

    public ?string $flash = null;

    public ?string $error = null;

    public function mount(Project $project): void
    {
        Gate::authorize('view', $project);

        $this->project = $project;
    }

    public function checkout(string $scheme): void
    {
        $this->reset('flash', 'error');

        Gate::authorize('checkout', $this->project);

        try {
            app(CheckoutService::class)->checkout($this->project, PaymentScheme::from($scheme));
            $this->flash = 'Jadwal pembayaran dibuat.';
        } catch (CheckoutException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function charge(int $installmentId): void
    {
        $this->reset('flash', 'error');

        $installment = Installment::findOrFail($installmentId);

        Gate::authorize('charge', $installment);

        try {
            // Idempotent in the service: an existing charge returns the same VA.
            app(PaymentService::class)->createCharge($installment);
            $this->flash = 'Nomor Virtual Account dibuat. Selesaikan pembayaran, status akan diperbarui otomatis.';
        } catch (PaymentException $e) {
            $this->error = $e->getMessage();
        }
    }

    public function render()
    {
        $installments = $this->project->installments()->orderBy('term_no')->get();

        return view('livewire.portal.project-payments', [
            'installments' => $installments,
            'canCheckout' => $this->project->contract_value !== null && $installments->isEmpty(),
            'schemes' => PaymentScheme::cases(),
        ]);
    }
}
