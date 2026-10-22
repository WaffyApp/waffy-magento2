<?php

declare(strict_types=1);

namespace Waffy\Ecommerce\Api;

use GuzzleHttp\Exception\BadResponseException;
use GuzzleHttp\Exception\GuzzleException;
use Waffy\Ecommerce\Dto\MilestoneInfo;
use Waffy\Ecommerce\Dto\Party;
use Waffy\Ecommerce\Dto\PreparedContract;
use Waffy\Ecommerce\Dto\ProductInfo;
use Waffy\Ecommerce\Exception\ApiException;
use Waffy\Ecommerce\Exception\NotImplementedException;
use Waffy\Ecommerce\Http\WaffyHttp;

/**
 * The API host's complex-contract endpoints (checkout steps 4–7).
 *
 * Every call takes the merchant token as Bearer; getting one is
 * Auth\TokenCache's job. None of these are cacheable — every order is a new
 * contract.
 */
class ContractsApi
{
    public function __construct(private readonly WaffyHttp $http)
    {
    }

    /**
     * The contract shortId Waffy embeds in the payment URL's path —
     * `https://external-dev.waffyapp.com/external/6VzpzZlR?...` → `6VzpzZlR`.
     *
     * It is the scope a payment ticket is issued against, and it is not returned
     * anywhere else in the flow, so it is read back off the step-7 URL.
     */
    public static function shortIdFrom(string $paymentUrl): string
    {
        $parts = parse_url($paymentUrl);
        if ($parts === false || !isset($parts['scheme'], $parts['host'])) {
            throw new ApiException(
                sprintf('Waffy start-payment returned an unparseable URL: "%s".', $paymentUrl),
                statusCode: 0,
                responseBody: ['data' => $paymentUrl],
            );
        }

        $segments = array_values(array_filter(
            explode('/', $parts['path'] ?? ''),
            static fn(string $segment): bool => $segment !== '',
        ));
        $shortId = $segments === [] ? '' : $segments[count($segments) - 1];

        if ($shortId === '') {
            throw new ApiException(
                sprintf('Waffy start-payment URL has no contract shortId in its path: "%s".', $paymentUrl),
                statusCode: 0,
                responseBody: ['data' => $paymentUrl],
            );
        }

        return $shortId;
    }

    /**
     * Step 4 — POST /api/external/contracts
     * Auth: Bearer merchantToken
     * Returns: data.id (contractId)
     */
    public function createContract(string $merchantToken, ProductInfo $product): string
    {
        $response = $this->http->apiPost($merchantToken, '/api/external/contracts', [
            'type'               => 'COMPLEX_CONTRACT',
            'senderRole'         => 'PROVIDER',
            'itemDetail'         => [
                'title'       => $product->title,
                'description' => $product->description,
                'images'      => $product->images,
            ],
            'returnPolicy'       => $product->returnPolicy,
            'returnFeePayee'     => $product->returnFeePayee,
            'waffyTermsAccepted' => true,
            'category'           => $product->category,
        ]);

        $contractId = $response['data']['id'] ?? null;
        if (!is_string($contractId) || $contractId === '') {
            throw new ApiException('Waffy create-contract response missing data.id.', statusCode: 0, responseBody: $response);
        }

        return $contractId;
    }

    /**
     * Step 5 — PATCH /api/external/contracts/{contractId}/milestones
     * Auth: Bearer merchantToken
     * Returns: data.milestones[0].id (milestoneId) and .shortId (the payment ticket's scope)
     */
    public function createMilestone(
        string $merchantToken,
        string $contractId,
        ProductInfo $product,
        MilestoneInfo $milestone,
    ): PreparedContract {
        $milestonePayload = [
            'type'               => 'MILESTONE_CONTRACT',
            'senderRole'         => 'PROVIDER',
            'itemDetail'         => [
                'title'       => $product->title,
                'description' => $product->description,
            ],
            'itemPrice'          => $milestone->amount,
            'currency'           => $milestone->currency,
            'returnPolicy'       => $product->returnPolicy,
            'returnFeePayee'     => $product->returnFeePayee,
            'deadLine'           => $milestone->deadline,
            'waffyTermsAccepted' => true,
        ];

        if (!empty($milestone->addOnFees)) {
            $milestonePayload['addOnFees'] = array_map(
                static fn($fee) => $fee->toArray(),
                $milestone->addOnFees,
            );
        }

        $response = $this->http->apiPatch(
            $merchantToken,
            '/api/external/contracts/' . $contractId . '/milestones',
            ['milestones' => [$milestonePayload]],
        );

        $milestoneId = $response['data']['milestones'][0]['id'] ?? null;
        if (!is_string($milestoneId) || $milestoneId === '') {
            throw new ApiException('Waffy create-milestone response missing data.milestones[0].id.', statusCode: 0, responseBody: $response);
        }

        $shortId = $response['data']['milestones'][0]['shortId'] ?? null;

        return new PreparedContract(
            $contractId,
            $milestoneId,
            is_string($shortId) && $shortId !== '' ? $shortId : null,
        );
    }

    /**
     * Step 6 — PATCH /api/external/contracts/{contractId}/parties
     * Auth: Bearer merchantToken
     *
     * @param Party[] $parties
     */
    public function addParties(
        string $merchantToken,
        string $contractId,
        string $milestoneId,
        array $parties,
    ): void {
        $this->http->apiPatch(
            $merchantToken,
            '/api/external/contracts/' . $contractId . '/parties',
            [
                'mileStonesParties' => [
                    $milestoneId => array_map(static fn(Party $p) => $p->toArray(), $parties),
                ],
            ],
        );
    }

    /**
     * Step 7 — GET /api/external/contracts/startPayment/{milestoneId}/{clientId}
     * Auth: Bearer merchantToken · Returns: data (URL string)
     */
    public function startPayment(
        string $merchantToken,
        string $milestoneId,
        string $clientId,
        string $redirectUrl,
        string $paymentType,
    ): string {
        $path = '/api/external/contracts/startPayment/' . $milestoneId . '/' . $clientId;

        try {
            $response = $this->http->request('GET', $this->http->apiUrl($path), [
                'headers' => [
                    'Authorization' => 'Bearer ' . $merchantToken,
                    'Accept'        => 'application/json',
                ],
                'query' => [
                    'redirectUrl' => $redirectUrl,
                    'paymentType' => $paymentType,
                ],
            ]);
        } catch (BadResponseException $e) {
            throw new ApiException(
                'Waffy start-payment failed: ' . $e->getMessage(),
                statusCode: $e->getResponse()->getStatusCode(),
                responseBody: json_decode((string) $e->getResponse()->getBody(), true) ?: null,
                previous: $e,
            );
        } catch (GuzzleException $e) {
            throw new ApiException('Waffy start-payment failed: ' . $e->getMessage(), statusCode: 0, previous: $e);
        }

        $data = $this->http->decode($response->getBody()->getContents());

        // Response: { "status": 200, "timestamp": "...", "data": "<url string>" }
        $paymentUrl = isset($data['data']) && is_string($data['data']) ? $data['data'] : null;

        if ($paymentUrl === null || $paymentUrl === '') {
            throw new ApiException('Waffy start-payment response missing data URL.', statusCode: 0, responseBody: $data);
        }

        return $paymentUrl;
    }

    /**
     * Remove an unpaid contract — for an order that died before payment (the
     * buyer cancelled or failed the OTP, the merchant cancelled the order).
     *
     * TBD(waffy): no endpoint exists for this yet — see #23 in
     * project-docs/04-open-questions.md (delete, or the collection's
     * "reject contract" action). Until then it always throws, so platforms
     * can wire the cleanup now and flag the order for a later retry.
     *
     * @throws NotImplementedException Always, for now.
     */
    public function deleteContract(string $merchantToken, string $contractId): void
    {
        throw new NotImplementedException(sprintf(
            'Waffy has no endpoint to delete an unpaid contract yet (contract %s). See open question #23.',
            $contractId,
        ));
    }
}
