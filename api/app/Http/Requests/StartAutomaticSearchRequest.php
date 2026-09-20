<?php

namespace App\Http\Requests;

use App\Binance\BinanceAccountClient;
use App\Binance\CapitalExceedsAvailableBalanceException;
use App\Binance\TrialCapitalValidator;
use App\MarketData\Timeframe;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use RuntimeException;

/**
 * Validates the HTTP shape of an "Iniciar automático" (Modo Automático)
 * request. Mirrors {@see SearchStrategiesRequest} and
 * {@see ActivateStrategyRequest}'s trial/real handling: `mode=real` is
 * rejected outright, and `mode=trial` capital must not exceed the Binance
 * Demo account's available USDT balance.
 *
 * Unlike "Buscar estrategia" (manual mode), this does not take a `symbol`:
 * Modo Automático uses the Opportunity Scanner to pick which symbols to
 * evaluate on each search attempt instead of a single user-chosen asset.
 */
class StartAutomaticSearchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'timeframe' => ['required', new Enum(Timeframe::class)],
            'capital' => ['required', 'numeric', 'gt:0'],
            'mode' => ['required', 'string', Rule::in(['trial', 'real'])],
        ];
    }

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            if ($this->mode() === 'real') {
                $validator->errors()->add('mode', 'El modo Real todavía no está disponible.');

                return;
            }

            try {
                $this->trialCapitalValidator()->assertCapitalIsAvailable($this->capital());
            } catch (CapitalExceedsAvailableBalanceException $exception) {
                $validator->errors()->add('capital', $exception->getMessage());
            } catch (RuntimeException) {
                $validator->errors()->add('mode', 'No se pudo consultar la cuenta Binance Demo.');
            }
        });
    }

    private function trialCapitalValidator(): TrialCapitalValidator
    {
        return new TrialCapitalValidator(new BinanceAccountClient(
            baseUrl: config('services.binance.base_url'),
            apiKey: config('services.binance.api_key'),
            apiSecret: config('services.binance.api_secret'),
        ));
    }

    public function timeframe(): Timeframe
    {
        return Timeframe::from($this->string('timeframe')->toString());
    }

    public function capital(): string
    {
        return $this->string('capital')->toString();
    }

    public function mode(): string
    {
        return $this->string('mode')->toString();
    }
}
