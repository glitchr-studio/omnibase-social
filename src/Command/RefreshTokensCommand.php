<?php

namespace Base\Social\Command;

use Base\Social\Service\Accounts;
use Omnipost\Auth\RefreshableInterface;
use Omnipost\Exception\OmnipostException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A fresh token for every account whose token dies within ten days
 * (Instagram's lives sixty, and is refreshed only while alive): the
 * account stays connected without anyone thinking of it. Suggested cron:
 * daily.
 */
#[AsCommand(name: 'social:refresh-tokens', description: 'Refresh the accounts\' tokens that die within ten days')]
class RefreshTokensCommand extends Command
{
    public function __construct(private readonly Accounts $accounts)
    {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('days', null, InputOption::VALUE_REQUIRED, 'Refresh what dies within this many days', '10')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Refresh every token, whatever its end');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $days = max(1, (int) $input->getOption('days'));
        $failed = 0;

        foreach ($this->accounts->names() as $name) {
            $token = $this->accounts->token($name);
            if (null === $token || (!$input->getOption('force') && !$token->isExpiring($days))) {
                continue;
            }
            try {
                if (!$this->accounts->provider($name) instanceof RefreshableInterface) {
                    continue;
                }
                $fresh = $this->accounts->refresh($name);
                $io->writeln(sprintf('%s: refreshed, until %s.', $name, $fresh?->expiresAt?->format('Y-m-d H:i') ?? '?'));
            } catch (OmnipostException $e) {
                $io->warning(sprintf('%s: %s', $name, $e->getMessage()));
                ++$failed;
            }
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
