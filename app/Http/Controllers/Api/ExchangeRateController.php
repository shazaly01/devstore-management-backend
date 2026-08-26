<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Pricing\StoreExchangeRateRequest;
use App\Http\Resources\Api\Pricing\CurrencyResource;
use App\Models\Currency;
use App\Models\ExchangeRate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Auth;

class ExchangeRateController extends Controller
{
    /**
     * استعراض قائمة العملات مع أحدث أسعار الصرف المسجلة لكل منها
     */
    public function index(): AnonymousResourceCollection
    {
        $currencies = Currency::query()
            ->with('latestExchangeRate')
            ->where('is_active', true)
            ->get();

        return CurrencyResource::collection($currencies);
    }

    /**
     * تسجيل سعر صرف جديد لعملة محددة وتوثيقه تاريخياً
     */
    public function store(StoreExchangeRateRequest $request): JsonResponse
    {
        $this->authorize('manageExchangeRates', ExchangeRate::class);

        $exchangeRate = ExchangeRate::create([
            'currency_id' => $request->validated('currency_id'),
            'rate'        => $request->validated('rate'),
            'rate_date'   => $request->validated('rate_date'),
            'user_id'     => Auth::id(),
            'notes'       => $request->validated('notes'),
        ]);

        return response()->json([
            'status'  => 'success',
            'message' => 'تم تسجيل سعر الصرف بنجاح وتحديث السعر المرجعي للعملة.',
            'data'    => [
                'id'          => $exchangeRate->id,
                'currency_id' => $exchangeRate->currency_id,
                'rate'        => (float) $exchangeRate->rate,
                'rate_date'   => $exchangeRate->rate_date->format('Y-m-d'),
                'notes'       => $exchangeRate->notes,
            ],
        ], 201);
    }

    /**
     * استعراض السجل التاريخي لأسعار صرف عملة محددة
     */
    public function history(Request $request, int $currencyId): JsonResponse
    {
        $this->authorize('manageExchangeRates', ExchangeRate::class);

        $currency = Currency::findOrFail($currencyId);

        $query = ExchangeRate::query()
            ->where('currency_id', $currencyId)
            ->with('user')
            ->latest('rate_date')
            ->latest('id');

        if ($request->filled('from_date')) {
            $query->whereDate('rate_date', '>=', $request->query('from_date'));
        }

        if ($request->filled('to_date')) {
            $query->whereDate('rate_date', '<=', $request->query('to_date'));
        }

        $perPage = $request->query('per_page', 15);
        $rates = $query->paginate($perPage);

        return response()->json([
            'status'   => 'success',
            'currency' => [
                'id'   => $currency->id,
                'name' => $currency->name,
                'code' => $currency->code,
            ],
            'rates'    => $rates,
        ]);
    }
}