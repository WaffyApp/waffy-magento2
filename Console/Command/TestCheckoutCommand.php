<?php

declare(strict_types=1);

namespace Waffy\Payment\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Waffy\Ecommerce\Dto\CheckoutRequest;
use Waffy\Ecommerce\Dto\CheckoutResult;
use Waffy\Ecommerce\Dto\CustomerInfo;
use Waffy\Ecommerce\Dto\MilestoneInfo;
use Waffy\Ecommerce\Dto\Party;
use Waffy\Ecommerce\Dto\ProductInfo;
use Waffy\Ecommerce\Exception\WaffyException;
use Waffy\Ecommerce\Support\PhoneNumber;
use Waffy\Payment\Model\Config;
use Waffy\Payment\Model\OrchestratorFactory;

/**
 * ddev magento waffy:checkout:test [options]
 *
 * Drives the Waffy ID checkout step by step with the connection from the setup
 * wizard, printing each result, and ends with the payment URL to open in a
 * browser. When the buyer's consent is required it stops before the ticket:
 * the OTP is collected by the checkout modal, not here.
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
             ->setDescription('Test the Waffy ID checkout steps end-to-end with the configured connection.')
             ->addOption('phone',  null, InputOption::VALUE_REQUIRED, 'Buyer phone (E.164 or 05XXXXXXXX)', '+966555555555')
             ->addOption('amount', null, InputOption::VALUE_REQUIRED, 'Order amount (SAR)', '50.00');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $phone  = PhoneNumber::toE164((string) $input->getOption('phone'));
        $amount = (float) $input->getOption('amount');

        $clientId     = $this->config->getClientId();
        $clientSecret = $this->config->getClientSecret();
        $adminMobile  = $this->config->getClientAdminEmail();
        $orgAccountId = $this->config->getOrgAccountId();

        if ($clientId === '' || $clientSecret === '' || $adminMobile === '' || $orgAccountId === '') {
            $output->writeln('<error>Waffy is not set up. Run the setup wizard: Stores → Waffy setup.</error>');
            return Command::FAILURE;
        }

        $output->writeln('<info>Waffy ID checkout test</info>');
        $output->writeln('  Environment     : ' . $this->config->getEnvironment());
        $output->writeln("  Client ID       : {$clientId}");
        $output->writeln("  Org account     : {$orgAccountId}");
        $output->writeln("  Buyer phone     : {$phone}");
        $output->writeln("  Amount          : SAR {$amount}");
        $output->writeln('');

        try {
            $request = new CheckoutRequest(
                clientId:            $clientId,
                clientSecret:        $clientSecret,
                clientAdminEmail:    $adminMobile,
                clientAdminPassword: $this->config->getClientAdminPassword(),
                customer:            new CustomerInfo(phoneNumber: $phone, firstName: 'Test', lastName: 'Buyer'),
                product:             new ProductInfo(title: 'Test Order #SDK-001', description: 'SDK test — 1x Test Product'),
                milestone:           MilestoneInfo::inDays(amount: $amount),
                parties:             Party::escrowSet($phone, $orgAccountId, $amount),
                redirectUrl:         'https://magento-test.ddev.site/waffy/checkout/return?order_id=SDK-TEST',
                paymentType:         $this->config->getPaymentType(),
            );
            $orchestrator = $this->orchestratorFactory->create();

            $output->writeln('<comment>Link Customer…</comment>');
            $registration = $orchestrator->partner->prepareCustomer($clientId, $clientSecret, $request->customer);
            $output->writeln('  waffyUserId     : ' . $registration->waffyUserId);
            $output->writeln('  consentRequired : ' . ($registration->consentRequired() ? 'yes' : 'no'));

            $output->writeln('<comment>Contract → milestone → parties…</comment>');
            $contract = $orchestrator->prepareContract($request);
            $output->writeln("  contractId      : {$contract->contractId}");
            $output->writeln("  milestoneId     : {$contract->milestoneId}");
            $output->writeln('  shortId         : ' . ($contract->shortId ?? '(none)'));

            $output->writeln('<comment>Start payment…</comment>');
            $paymentUrl = $orchestrator->startPayment($request, $contract);
            $output->writeln("  paymentUrl      : {$paymentUrl}");

            if ($registration->consentRequired()) {
                $output->writeln('');
                $output->writeln('<comment>The buyer must consent first (OTP at checkout) — no ticket issued.</comment>');
                $output->writeln('Open the payment URL above to pay with a live Waffy login instead.');

                return Command::SUCCESS;
            }

            $output->writeln('<comment>Payment ticket…</comment>');
            $ticket = $orchestrator->paymentTicket($request, $contract, $paymentUrl);

            $output->writeln('');
            $output->writeln('<info>✓ Success — open this URL in a browser to pay:</info>');
            $output->writeln(CheckoutResult::withTicket($contract, $paymentUrl, $ticket)->paymentUrlWithBuyer());

            return Command::SUCCESS;
        } catch (WaffyException $e) {
            $output->writeln('');
            $output->writeln('<error>✗ ' . get_class($e) . ': ' . ($e->getUserMessage() ?? $e->getMessage()) . '</error>');

            return Command::FAILURE;
        }
    }
}
