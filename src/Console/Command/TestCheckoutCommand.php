<?php

declare(strict_types=1);

namespace Waffy\Payment\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waffy\Ecommerce\Dto\CheckoutRequest;
use Waffy\Ecommerce\Dto\CustomerInfo;
use Waffy\Ecommerce\Dto\MilestoneInfo;
use Waffy\Ecommerce\Dto\Party;
use Waffy\Ecommerce\Dto\ProductInfo;
use Waffy\Payment\Model\Config;
use Waffy\Payment\Model\OrchestratorFactory;

/**
 * ddev magento waffy:checkout:test [options]
 *
 * Tests the full 7-step SDK checkout flow using credentials from admin config.
 * Prints the resulting paymentUrl so you can open it in a browser.
 */
class TestCheckoutCommand extends Command
{
    public function __construct(
        private readonly Config $config,
        private readonly OrchestratorFactory $orchestratorFactory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('waffy:checkout:test')
             ->setDescription('Test the Waffy SDK initiateCheckout() flow end-to-end.')
             ->addOption('phone',    null, InputOption::VALUE_REQUIRED, 'Buyer phone (E.164)',       '+966555555555')
             ->addOption('amount',   null, InputOption::VALUE_REQUIRED, 'Order amount (SAR)',         '50.00')
             ->addOption('user-id',  null, InputOption::VALUE_REQUIRED, 'clientUserId for the buyer', 'test_buyer_001');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $phone    = (string) $input->getOption('phone');
        $amount   = (float)  $input->getOption('amount');
        $userId   = (string) $input->getOption('user-id');

        $clientId     = $this->config->getClientId();
        $clientSecret = $this->config->getClientSecret();
        $merchantPhone = $this->config->getMerchantPhone();

        if ($clientId === '' || $clientSecret === '') {
            $output->writeln('<error>Client ID or Client Secret not configured. Go to Stores → Configuration → Payment Methods → Waffy.</error>');
            return Command::FAILURE;
        }
        if ($merchantPhone === '') {
            $output->writeln('<error>Merchant Phone not configured.</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>Starting Waffy checkout test...</info>');
        $output->writeln("  Client ID      : {$clientId}");
        $output->writeln("  Buyer phone    : {$phone}");
        $output->writeln("  Merchant phone : {$merchantPhone}");
        $output->writeln("  Amount         : SAR {$amount}");
        $output->writeln('');

        $clientAdminEmail    = $this->config->getClientAdminEmail();
        $clientAdminPassword = $this->config->getClientAdminPassword();

        if ($clientAdminEmail === '' || $clientAdminPassword === '') {
            $output->writeln('<error>Client Admin Email or Password not configured. Go to Stores → Configuration → Payment Methods → Waffy.</error>');
            return Command::FAILURE;
        }

        $request = new CheckoutRequest(
            clientId:            $clientId,
            clientSecret:        $clientSecret,
            clientAdminEmail:    $clientAdminEmail,
            clientAdminPassword: $clientAdminPassword,
            customer: new CustomerInfo(
                phoneNumber: $phone,
                firstName:   'Test',
                lastName:    'Buyer',
            ),
            product: new ProductInfo(
                title:          'Test Order #SDK-001',
                description:    'SDK test — 1x Test Product',
                images:         [],
                category:       $this->config->getCategory(),
                returnPolicy:   $this->config->getReturnPolicy(),
                returnFeePayee: $this->config->getReturnFeePayee(),
            ),
            milestone: new MilestoneInfo(
                amount:   $amount,
                deadline: (new \DateTimeImmutable())
                    ->modify('+' . $this->config->getMilestoneDeadlineDays() . ' days')
                    ->format('Y-m-d\TH:i:s.000\Z'),
                currency: 'SAR',
            ),
            parties: [
                new Party(phoneNumber: $phone,          role: 'CUSTOMER', amount: $amount),
                new Party(phoneNumber: $merchantPhone,  role: 'PROVIDER', amount: $amount, isSender: true),
            ],
            redirectUrl: 'https://magento-test.ddev.site/waffy/checkout/return?order_id=SDK-TEST',
            paymentType: $this->config->getPaymentType(),
        );

        try {
            $orchestrator = $this->orchestratorFactory->create();

            // ── Step 1a: app token (client_credentials) ──────────────────────
            $output->writeln('<comment>Step 1a: App Token (client_credentials)...</comment>');
            $appToken = $this->callStep($orchestrator, 'fetchAppToken', [$clientId, $clientSecret]);
            $output->writeln('  appToken      : ' . substr($appToken, 0, 40) . '...');

            // ── Step 1b: merchant token (admin password grant) ───────────────
            $output->writeln('<comment>Step 1b: Merchant Token (admin login)...</comment>');
            $merchantToken = $this->callStep($orchestrator, 'fetchMerchantToken', [
                $clientId, $clientSecret,
                $clientAdminEmail, $clientAdminPassword,
            ]);
            $output->writeln('  merchantToken : ' . substr($merchantToken, 0, 40) . '...');

            // ── Step 2: sign up ──────────────────────────────────────────────
            $output->writeln('<comment>Step 2: Customer Sign-Up...</comment>');
            $derivedClientUserId = $request->customer->clientUserId ?? ltrim($request->customer->phoneNumber, '+');
            $output->writeln('  clientUserId  : ' . $derivedClientUserId);
            $clientUserToken = $this->callStep($orchestrator, 'signUpCustomer', [
                $appToken,
                $derivedClientUserId,
                $request->customer->phoneNumber,
                $request->customer->firstName,
                $request->customer->lastName,
            ]);
            $output->writeln('  clientUserToken : ' . $clientUserToken);

            // ── Step 3: customer login ───────────────────────────────────────
            $output->writeln('<comment>Step 3: Customer Login (password grant)...</comment>');
            $customerToken = $this->callStep($orchestrator, 'fetchCustomerToken', [
                $clientId, $clientSecret,
                $request->customer->phoneNumber,
                $clientUserToken,
            ]);
            $output->writeln('  customerToken : ' . substr($customerToken, 0, 40) . '...');

            // ── Steps 4-7 via initiateCheckout ───────────────────────────────
            $output->writeln('<comment>Steps 4–7: Contract → Milestone → Parties → Payment URL...</comment>');
            $result = $this->orchestratorFactory->create()->initiateCheckout($request);

            $output->writeln('');
            $output->writeln('<info>✓ Success!</info>');
            $output->writeln('');
            $output->writeln("  <comment>paymentUrl    :</comment> {$result->paymentUrl}");
            $output->writeln("  <comment>customerToken :</comment> " . substr($result->customerToken, 0, 40) . '...');
            $output->writeln("  <comment>contractId    :</comment> {$result->contractId}");
            $output->writeln("  <comment>milestoneId   :</comment> {$result->milestoneId}");
            $output->writeln('');
            $output->writeln('<info>Open the paymentUrl in a browser to complete the payment flow.</info>');

            return Command::SUCCESS;

        } catch (\Throwable $e) {
            $output->writeln('');
            $output->writeln('<error>✗ ' . get_class($e) . ': ' . $e->getMessage() . '</error>');
            return Command::FAILURE;
        }
    }

    /** Calls a private method on the orchestrator via reflection — for step-by-step debugging only. */
    private function callStep(object $obj, string $method, array $args): mixed
    {
        $ref = new \ReflectionMethod($obj, $method);
        $ref->setAccessible(true);
        return $ref->invokeArgs($obj, $args);
    }
}
