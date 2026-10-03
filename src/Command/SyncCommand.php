<?php

namespace Base\Social\Command;

use Base\Social\Service\Accounts;
use Base\Social\Service\FeedSync;
use Omnipost\Exception\OmnipostException;
use Omnipost\FeedInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Reads the accounts' last posts for the wall (all the providers that have
 * a feed, or one). Suggested cron: hourly.
 */
#[AsCommand(name: 'social:sync', description: 'Read the accounts\' last posts for the wall')]
class SyncCommand extends Command
{
    public function __construct(
        private readonly Accounts $accounts,
        private readonly FeedSync $sync,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('provider', InputArgument::OPTIONAL, 'Only this provider: instagram, youtube...')
            ->addOption('limit', 'l', InputOption::VALUE_REQUIRED, 'Posts to read (social.feed.limit otherwise)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $names = $input->getArgument('provider') ? [(string) $input->getArgument('provider')] : $this->accounts->names();
        $limit = null !== $input->getOption('limit') ? (int) $input->getOption('limit') : null;

        $failed = 0;
        foreach ($names as $name) {
            if (!$this->accounts->has($name)) {
                $io->error(sprintf('No "%s" provider: social.providers lists %s.', $name, implode(', ', $this->accounts->names()) ?: 'none'));
                ++$failed;
                continue;
            }
            try {
                if (!$this->accounts->provider($name) instanceof FeedInterface) {
                    continue;
                }
                $io->writeln(sprintf('%s: %d posts read.', $name, $this->sync->sync($name, $limit)));
            } catch (OmnipostException $e) {
                $io->warning(sprintf('%s: %s', $name, $e->getMessage()));
                ++$failed;
            }
        }

        return $failed ? Command::FAILURE : Command::SUCCESS;
    }
}
