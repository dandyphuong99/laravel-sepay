<?php

namespace SePay\SePay\Http\Controllers;

use App\Models\User;
use Illuminate\Contracts\Container\BindingResolutionException;
use Illuminate\Contracts\Routing\ResponseFactory;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use SePay\SePay\Datas\SePayWebhookData;
use SePay\SePay\Events\SePayWebhookEvent;
use SePay\SePay\Models\SePayTransaction;

class SePayController extends Controller
{
    /**
     * @return Response|ResponseFactory
     *
     * @throws BindingResolutionException
     */
    public function webhook(Request $request)
    {
        $token = $this->bearerToken($request);

        throw_if(
            config('sepay.webhook_token') && $token !== config('sepay.webhook_token'),
            ValidationException::withMessages(['message' => ['Invalid Token']])
        );

        $sePayWebhookData = new SePayWebhookData(
            $request->integer('id'),
            $request->string('gateway')->value(),
            $request->string('transactionDate')->value(),
            $request->string('accountNumber')->value(),
            $request->string('subAccount')->value(),
            $request->string('code')->value(),
            $request->string('content')->value(),
            $request->string('transferType')->value(),
            $request->string('description')->value(),
            $request->integer('transferAmount'),
            $request->string('referenceCode')->value(),
            $request->integer('accumulated')
        );

        throw_if(
            SePayTransaction::query()->whereId($sePayWebhookData->id)->exists(),
            ValidationException::withMessages(['message' => ['transaction này đã thực hiện']])
        );

        $model = new SePayTransaction;
        $model->id = $sePayWebhookData->id;
        $model->gateway = $sePayWebhookData->gateway;
        $model->transactionDate = $sePayWebhookData->transactionDate;
        $model->accountNumber = $sePayWebhookData->accountNumber;
        $model->subAccount = $sePayWebhookData->subAccount;
        $model->code = $sePayWebhookData->code;
        $model->content = $sePayWebhookData->content;
        $model->transferType = $sePayWebhookData->transferType;
        $model->description = $sePayWebhookData->description;
        $model->transferAmount = $sePayWebhookData->transferAmount;
        $model->referenceCode = $sePayWebhookData->referenceCode;
        $model->save();

        // Lấy ra user id hoặc order id ví dụ: SE_123456, SE_abcd-efgh
        $pattern = '/\b' . config('sepay.pattern') . '([a-zA-Z0-9_]+)\b/';
        preg_match($pattern, $sePayWebhookData->content, $matches);

        Log::info('[SEPay DEBUG] Pattern: ' . config('sepay.pattern'));
        Log::info('[SEPay DEBUG] Content: ' . $sePayWebhookData->content);
        Log::info('[SEPay DEBUG] Matches:', $matches);

        if (isset($matches[0])) {
            // Lấy bỏ phần pattern chỉ còn lại id ex: 123456, abcd-efgh
            $info = Str::of($matches[0])->replaceFirst(config('sepay.pattern'), '')->value();
            Log::info('[SEPay DEBUG] info:'. $info);
            event(new SePayWebhookEvent($info, $sePayWebhookData));
        }

        return response()->noContent();
    }

    /**
     * Get the bearer token from the request headers.
     *
     * @return string|null
     */
    private function bearerToken(Request $request)
    {
        $header = $request->header('Authorization', '');

        $position = strrpos($header, 'Apikey ');

        if ($position !== false) {
            $header = substr($header, $position + 7);

            return str_contains($header, ',') ? (strstr($header, ',', true) ?: null) : $header;
        }

        return null;
    }
}
