<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\CnabLayout;
use App\Events\PaymentRequest\PaymentRequestBatchImported;
use App\Events\PaymentRequest\PaymentRequestCreated;
use App\Events\PaymentRequest\PaymentRequestStatusChanged;
use App\Integrations\Cnab\CnabAdapterResolver;
use App\Integrations\Cnab\Itau\Itau240RemittanceAdapter;
use App\Integrations\Ocr\BoletoOcrClient;
use App\Integrations\Ocr\LocalBoletoOcrClient;
use App\Integrations\Ocr\NullBoletoOcrClient;
use App\Integrations\Spreadsheet\OpenSpoutSpreadsheetReader;
use App\Integrations\Spreadsheet\SpreadsheetReader;
use App\Listeners\PaymentRequest\LogPaymentRequestActivity;
use App\Listeners\PaymentRequest\LogPaymentRequestBatchImported;
use App\Listeners\PaymentRequest\NotifyImportBatchCompleted;
use App\Models\Appropriation;
use App\Models\Approval;
use App\Models\ApprovalRule;
use App\Models\Attachment;
use App\Models\AttachmentBatch;
use App\Models\AttachmentBatchItem;
use App\Models\Bank;
use App\Models\Branch;
use App\Models\BranchBankAccount;
use App\Models\CnabConfig;
use App\Models\CnabFile;
use App\Models\Company;
use App\Models\CostCenter;
use App\Models\ImportBatch;
use App\Models\ImportTemplate;
use App\Models\PaymentRequest;
use App\Models\PaymentRequestStatusHistory;
use App\Models\PaymentSettlement;
use App\Models\Supplier;
use App\Models\SupplierCompanyPaymentMethod;
use App\Models\User;
use App\Observers\AttachmentBatchObserver;
use App\Observers\CnabFileObserver;
use App\Observers\ImportBatchObserver;
use App\Observers\PaymentSettlementObserver;
use App\Policies\AppropriationPolicy;
use App\Policies\ApprovalPolicy;
use App\Policies\ApprovalRulePolicy;
use App\Policies\AttachmentBatchItemPolicy;
use App\Policies\AttachmentBatchPolicy;
use App\Policies\AttachmentPolicy;
use App\Policies\BankPolicy;
use App\Policies\BranchBankAccountPolicy;
use App\Policies\BranchPolicy;
use App\Policies\CnabConfigPolicy;
use App\Policies\CnabFilePolicy;
use App\Policies\CompanyPolicy;
use App\Policies\CostCenterPolicy;
use App\Policies\ImportBatchPolicy;
use App\Policies\ImportTemplatePolicy;
use App\Policies\PaymentRequestPolicy;
use App\Policies\PaymentRequestStatusHistoryPolicy;
use App\Policies\PaymentSettlementPolicy;
use App\Policies\SupplierCompanyPaymentMethodPolicy;
use App\Policies\SupplierPolicy;
use App\Policies\UserPolicy;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * @var array<class-string, class-string>
     */
    private const POLICIES = [
        Company::class => CompanyPolicy::class,
        Branch::class => BranchPolicy::class,
        BranchBankAccount::class => BranchBankAccountPolicy::class,
        User::class => UserPolicy::class,
        CostCenter::class => CostCenterPolicy::class,
        Appropriation::class => AppropriationPolicy::class,
        Supplier::class => SupplierPolicy::class,
        SupplierCompanyPaymentMethod::class => SupplierCompanyPaymentMethodPolicy::class,
        Bank::class => BankPolicy::class,
        PaymentRequest::class => PaymentRequestPolicy::class,
        Attachment::class => AttachmentPolicy::class,
        PaymentRequestStatusHistory::class => PaymentRequestStatusHistoryPolicy::class,
        ApprovalRule::class => ApprovalRulePolicy::class,
        Approval::class => ApprovalPolicy::class,
        ImportTemplate::class => ImportTemplatePolicy::class,
        ImportBatch::class => ImportBatchPolicy::class,
        AttachmentBatch::class => AttachmentBatchPolicy::class,
        AttachmentBatchItem::class => AttachmentBatchItemPolicy::class,
        PaymentSettlement::class => PaymentSettlementPolicy::class,
        CnabConfig::class => CnabConfigPolicy::class,
        CnabFile::class => CnabFilePolicy::class,
    ];

    public function register(): void
    {
        $this->app->bind(BoletoOcrClient::class, fn (): BoletoOcrClient => match (config('rjet.ocr.driver')) {
            'null' => new NullBoletoOcrClient,
            default => new LocalBoletoOcrClient,
        });

        $this->app->bind(SpreadsheetReader::class, OpenSpoutSpreadsheetReader::class);

        $this->app->singleton(CnabAdapterResolver::class, fn (Application $app): CnabAdapterResolver => new CnabAdapterResolver($app, [
            CnabLayout::Itau240->value => Itau240RemittanceAdapter::class,
        ]));
    }

    public function boot(): void
    {
        Model::preventLazyLoading(! app()->isProduction());

        Relation::enforceMorphMap([
            'supplier' => Supplier::class,
            'payment_request' => PaymentRequest::class,
            'user' => User::class,
            'attachment_batch' => AttachmentBatch::class,
        ]);

        foreach (self::POLICIES as $model => $policy) {
            Gate::policy($model, $policy);
        }

        ImportBatch::observe(ImportBatchObserver::class);
        AttachmentBatch::observe(AttachmentBatchObserver::class);
        PaymentSettlement::observe(PaymentSettlementObserver::class);
        CnabFile::observe(CnabFileObserver::class);

        Event::listen(PaymentRequestCreated::class, [LogPaymentRequestActivity::class, 'handleCreated']);
        Event::listen(PaymentRequestStatusChanged::class, [LogPaymentRequestActivity::class, 'handleStatusChanged']);
        Event::listen(PaymentRequestBatchImported::class, [LogPaymentRequestBatchImported::class, 'handle']);
        Event::listen(PaymentRequestBatchImported::class, [NotifyImportBatchCompleted::class, 'handle']);
        // Approval / PaymentRequest notification listeners are auto-discovered via handle().
        // Attachment listeners rely on discovery only: explicit Event::listen would register them twice.
    }
}
