<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\WhatsApp\QueryHandlerRegistry;
use App\Services\WhatsApp\Handlers\SalesQueryHandler;
use App\Services\WhatsApp\Handlers\PartyBalanceQueryHandler;
use App\Services\WhatsApp\Handlers\ItemStockQueryHandler;
use App\Services\WhatsApp\Handlers\LatestInvoiceQueryHandler;
use App\Services\WhatsApp\Handlers\TopDebtorsQueryHandler;
use App\Services\WhatsApp\Handlers\LowStockQueryHandler;
use App\Services\WhatsApp\Handlers\LiquidityQueryHandler;
use App\Services\WhatsApp\Handlers\TopCreditorsQueryHandler;
use App\Services\WhatsApp\Handlers\ExpensesQueryHandler;
use App\Services\WhatsApp\Handlers\HelpQueryHandler;

class AppServiceProvider extends ServiceProvider
{
    /**
     * قائمة معالجات استعلامات الواتساب لتسجيلها في النظام
     *
     * @var array<int, class-string>
     */
    protected array $whatsAppHandlers = [
        SalesQueryHandler::class,
        PartyBalanceQueryHandler::class,
        ItemStockQueryHandler::class,
        LatestInvoiceQueryHandler::class,
        TopDebtorsQueryHandler::class,
        LowStockQueryHandler::class,
        LiquidityQueryHandler::class,
        TopCreditorsQueryHandler::class,
        ExpensesQueryHandler::class,
        HelpQueryHandler::class,
    ];

    /**
     * Register any application services.
     */
    public function register(): void
    {
        // تسجيل سجل استعلامات الواتساب المركزي كـ Singleton في حاوية الخدمات
        $this->app->singleton(QueryHandlerRegistry::class, function ($app) {
            $registry = new QueryHandlerRegistry();

            foreach ($this->whatsAppHandlers as $handlerClass) {
                $registry->register($app->make($handlerClass));
            }

            return $registry;
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}