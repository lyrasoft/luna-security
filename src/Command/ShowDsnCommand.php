<?php

declare(strict_types=1);

namespace Lyrasoft\Security\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;
use Windwalker\Console\CommandInterface;
use Windwalker\Console\CommandWrapper;
use Windwalker\Console\IOInterface;
use Windwalker\Core\Manager\DatabaseManager;
use Windwalker\Database\Driver\Pdo\DsnHelper;
use Windwalker\Utilities\Str;

use function Windwalker\fs;

#[CommandWrapper(
    description: 'Show DB Connection params as DSN and stripes from env file.'
)]
class ShowDsnCommand implements CommandInterface
{
    public function __construct(protected DatabaseManager $databaseManager)
    {
    }

    /**
     * configure
     *
     * @param  Command  $command
     *
     * @return  void
     */
    public function configure(Command $command): void
    {
        $command->addArgument(
            'connection',
            InputArgument::OPTIONAL,
            'The database connection.'
        );

        $command->addOption(
            'no-credential',
            'c',
            InputOption::VALUE_NONE,
            'DSN do not contains credentials.'
        );

        $command->addOption(
            'remove',
            'x',
            InputOption::VALUE_OPTIONAL,
            'Remove database params from env file.',
            false
        );

        $command->addOption(
            'remove-env',
            'e',
            InputOption::VALUE_REQUIRED,
            'The env file name to remove.'
        );
    }

    /**
     * Executes the current command.
     *
     * @param  IOInterface  $io
     *
     * @return  int Return 0 is success, 1-255 is failure.
     */
    public function execute(IOInterface $io): int
    {
        $conn = $io->getArgument('connection');
        $noCredential = $io->getOption('no-credential');
        $remove = $io->getOption('remove');
        $envName = $io->getOption('remove-env');

        $db = $this->databaseManager->get($conn);

        $options = $db->getOptions();

        $dsn = $options['dsn'];

        if (!$dsn) {
            $params = [
                'host' => $options['host'],
                'dbname' => $options['dbname'],
                'port' => $options['port'],
                'user' => $options['user'],
                'password' => $options['password'],
                'charset' => $options['charset'] ?: 'utf8mb4',
                ...$options['driverOptions']
            ];

            if ($noCredential) {
                unset($params['user'], $params['password']);
            }

            $dsn = DsnHelper::build(
                $params,
                Str::removeLeft($options['driver'], 'pdo_')
            );
        }

        $canRemove = false;

        if ($remove !== false) {
            $canRemove = $io->askConfirmation('This will remove all database params from your env file. [Y/n]: ');
        }

        if ($canRemove) {
            $prefix = 'DATABASE_';

            if (is_string($remove)) {
                $prefix = $remove;
            }

            if ($envName) {
                $envPath = WINDWALKER_ROOT . '/' . $envName;
            } else {
                $envPath = WINDWALKER_ROOT . '/.env';
            }

            $envFile = fs($envPath);

            $encContent = (string) $envFile->read();

            $i = 0;

            $encContent = preg_replace_callback(
                "/\\n({$prefix}[\\w_]+)=(.*)/",
                static function (array $matches) use (&$i) {
                    $envKey = $matches[1];

                    if ($envKey === 'DATABASE_DRIVER') {
                        return $matches[0];
                    }

                    $i++;

                    if ($i === 1) {
                        return "\n# Database DSN encrypted";
                    }

                    return '';
                },
                $encContent
            );

            $envFile->write($encContent);
        }

        $io->writeln($dsn);

        return 0;
    }
}
