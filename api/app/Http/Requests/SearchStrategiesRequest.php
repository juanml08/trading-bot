<?php

namespace App\Http\Requests;

use App\Actions\Strategy\SearchStrategiesAction;
use App\Binance\BinanceAccountClient;
use App\Binance\CapitalExceedsAvailableBalanceException;
use App\Binance\TrialCapitalValidator;
use App\MarketData\Timeframe;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Enum;
use RuntimeException;

/**
 * Validates the HTTP shape of a "Buscar estrategia" request (required
 * fields, date/enum formats). It does not re-validate the business
 * invariants {@see SearchStrategiesAction} already
 * enforces (non-empty symbol, capital > 0) beyond what is needed to reject
 * malformed input early with a 422 instead of an exception.
 *
 * It does enforce one invariant the Action deliberately never sees: for
 * `mode=trial`, capital must not exceed the Binance Demo account's
 * available USDT balance (see {@see TrialCapitalValidator}). `mode=real`
 * is rejected outright — Binance Real is not implemented yet.
 */
class SearchStrategiesRequest extends FormRequest
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
            'symbol' => ['required', 'string'],
            'timeframe' => ['required', new Enum(Timeframe::class)],
            'from' => ['required', 'date'],
            'to' => ['required', 'date', 'after:from'],
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

    public function symbol(): string
    {
        return $this->string('symbol')->toString();
    }

    public function timeframe(): Timeframe
    {
        return Timeframe::from($this->string('timeframe')->toString());
    }

    public function from(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->string('from')->toString());
    }

    public function to(): CarbonImmutable
    {
        return CarbonImmutable::parse($this->string('to')->toString());
    }

    /**
     * The HTTP `capital` field is evaluation capital, not any real account
     * balance or authorized limit — see {@see SearchStrategiesAction}.
     */
    public function capital(): string
    {
        return $this->string('capital')->toString();
    }

    public function mode(): string
    {
        return $this->string('mode')->toString();
    }
}
