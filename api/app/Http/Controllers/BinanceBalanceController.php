<?php

namespace App\Http\Controllers;

use App\Binance\BinanceAccountClient;
use App\Binance\BinanceBalance;
use Illuminate\Http\JsonResponse;
use RuntimeException;

/**
 * HTTP entry point for reading the Binance Demo account balance. This is a
 * thin HTTP layer: it asks {@see BinanceAccountClient} for the balances and
 * returns them as JSON. It contains no strategy, capital, or execution
 * logic of its own.
 */
class BinanceBalanceController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $client = new BinanceAccountClient(
            baseUrl: config('services.binance.base_url'),
            apiKey: config('services.binance.api_key'),
            apiSecret: config('services.binance.api_secret'),
        );

        try {
            $balances = $client->getBalances();
        } catch (RuntimeException) {
            return response()->json([
                'message' => 'No se pudo consultar la cuenta Binance Demo.',
            ], 502);
        }

        return response()->json([
            'balances' => array_map(
                fn (BinanceBalance $balance) => [
                    'asset' => $balance->asset,
                    'free' => $balance->free,
                    'locked' => $balance->locked,
                ],
                $balances,
            ),
        ]);
    }
}
