<?php
namespace Platformsh\Cli\Command\Environment;

use Platformsh\Cli\Command\CommandBase;
use Platformsh\Cli\Exception\RootNotFoundException;
use Platformsh\Cli\Service\Ssh;
use Platformsh\Client\Exception\EnvironmentStateException;
use Platformsh\Client\Model\Environment;
use Platformsh\Client\Model\Project;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class EnvironmentPushCommand extends CommandBase
{

    protected function configure()
    {
        $this
            ->setName('environment:push')
            ->setAliases(['push'])
            ->setDescription('Push code to an environment')
            ->addArgument('source', InputArgument::OPTIONAL, 'The source ref: a branch name or commit hash', 'HEAD')
            ->addOption('target', null, InputOption::VALUE_REQUIRED, 'The target branch name')
            ->addOption('force', 'f', InputOption::VALUE_NONE, 'Allow non-fast-forward updates')
            ->addOption('force-with-lease', null, InputOption::VALUE_NONE, 'Allow non-fast-forward updates, if the remote-tracking branch is up to date')
            ->addOption('set-upstream', 'u', InputOption::VALUE_NONE, 'Set the target environment as the upstream for the source branch')
            ->addOption('branch', null, InputOption::VALUE_NONE, 'Create the environment as a branch')
            ->addOption('activate', null, InputOption::VALUE_NONE, 'Activate the environment after pushing')
            ->addOption('parent', null, InputOption::VALUE_REQUIRED, 'Set a new environment parent (only used with --activate or --branch)');
        $this->addWaitOptions();
        $this->addProjectOption()
            ->addEnvironmentOption();
        Ssh::configureInput($this->getDefinition());
        $this->addExample('Push code to the current environment');
        $this->addExample('Push code, without waiting for deployment', '--no-wait');
        $this->addExample(
            'Push code and activate the environment as a child of \'develop\'',
            '--activate --parent develop'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->validateInput($input, true);
        $projectRoot = $this->getProjectRoot();
        if (!$projectRoot) {
            throw new RootNotFoundException();
        }

        /** @var \Platformsh\Cli\Service\Git $git */
        $git = $this->getService('git');
        $git->setDefaultRepositoryDir($projectRoot);

        // Validate the source argument.
        $source = $input->getArgument('source');
        if ($source === '') {
            $this->stdErr->writeln('The <error><source></error> argument cannot be specified as an empty string.');
            return 1;
        } elseif (strpos($source, ':') !== false
            || !($sourceRevision = $git->execute(['rev-parse', '--verify', $source]))) {
            $this->stdErr->writeln(sprintf('Invalid source ref: <error>%s</error>', $source));
            return 1;
        }

        $this->stdErr->writeln(
            sprintf('Source revision: %s', $sourceRevision),
            OutputInterface::VERBOSITY_VERY_VERBOSE
        );

        // Find the target branch name (--target, the name of the current
        // environment, or the Git branch name).
        if ($input->getOption('target')) {
            $target = $input->getOption('target');
        } elseif ($this->hasSelectedEnvironment()) {
            $target = $this->getSelectedEnvironment()->id;
        } elseif ($currentBranch = $git->getCurrentBranch()) {
            $target = $currentBranch;
        } else {
            $this->stdErr->writeln('Could not determine target environment name.');
            return 1;
        }

        // Guard against accidental pushing to production.
        /** @var \Platformsh\Cli\Service\QuestionHelper $questionHelper */
        $questionHelper = $this->getService('question_helper');
        if ($target === 'master'
            && !$questionHelper->confirm(
                'Are you sure you want to push to the <comment>master</comment> (production) branch?'
            )) {
            return 1;
        }

        // Determine whether the target environment is new.
        $project = $this->getSelectedProject();
        $targetEnvironment = $this->api()->getEnvironment($target, $project);
        $this->stdErr->writeln(sprintf(
            'Pushing <info>%s</info> to the %s environment <info>%s</info>',
            $source,
            $targetEnvironment ? 'existing' : 'new',
            $target
        ));

        $activate = false;
        $createAsBranch = false;
        $parentId = null;
        if ($target !== 'master') {
            // Determine whether to create the environment as a branch.
            if (!$targetEnvironment) {
                $createAsBranch = $input->getOption('branch')
                    || ($input->isInteractive() && $questionHelper->confirm(sprintf(
                        'Create <info>%s</info> as an active branch?',
                        $target
                    )));
            }

            // Determine whether to activate the environment after pushing.
            if ($targetEnvironment && $targetEnvironment->status === 'inactive') {
                $activate = $input->getOption('activate')
                    || ($input->isInteractive() && $questionHelper->confirm(sprintf(
                        'Activate <info>%s</info> after pushing?',
                        $target
                    )));
            }

            // If activating, determine what the environment's parent should be.
            if ($activate || $createAsBranch) {
                $parentId = $input->getOption('parent') ?: $this->findTargetParent($project, $targetEnvironment);
            }

            if ($createAsBranch) {
                $parentEnvironment = $this->api()->getEnvironment($parentId, $project);
                if (!$parentEnvironment) {
                    throw new \RuntimeException("Parent environment not found: $parentId");
                }
                if (!$parentEnvironment->operationAvailable('branch', true)) {
                    $this->stdErr->writeln(sprintf(
                        'Operation not available: the environment %s cannot be branched.',
                        $this->api()->getEnvironmentLabel($parentEnvironment, 'error')
                    ));

                    if ($parentEnvironment->is_dirty) {
                        $this->stdErr->writeln('An activity is currently pending or in progress on the environment.');
                    } elseif (!$parentEnvironment->isActive()) {
                        $this->stdErr->writeln('The environment is not active.');
                    }

                    return 1;
                }

                $activity = $parentEnvironment->branch($target, $target);
                $this->stdErr->writeln(sprintf(
                    'Branched <info>%s</info> from parent %s',
                    $target,
                    $this->api()->getEnvironmentLabel($parentEnvironment)
                ));
                $this->debug(sprintf('Branch activity ID / state: %s / %s', $activity->id, $activity->state));
            }
        }

        // Ensure the correct Git remote exists.
        /** @var \Platformsh\Cli\Local\LocalProject $localProject */
        $localProject = $this->getService('local.project');
        $localProject->ensureGitRemote($projectRoot, $project->getGitUrl());

        // Build the Git command.
        $gitArgs = [
            'push',
            $this->config()->get('detection.git_remote_name'),
            $source . ':refs/heads/' . $target,
        ];
        foreach (['force', 'force-with-lease', 'set-upstream'] as $option) {
            if ($input->getOption($option)) {
                $gitArgs[] = '--' . $option;
            }
        }

        // Build the SSH command to use with Git.
        /** @var \Platformsh\Cli\Service\Ssh $ssh */
        $ssh = $this->getService('ssh');
        $extraSshOptions = [];
        $env = [];
        if (!$this->shouldWait($input)) {
            $extraSshOptions['SendEnv'] = 'PLATFORMSH_PUSH_NO_WAIT';
            $env['PLATFORMSH_PUSH_NO_WAIT'] = '1';
        }
        $git->setSshCommand($ssh->getSshCommand($extraSshOptions));

        // Push.
        $success = $git->execute($gitArgs, null, false, false, $env);
        if (!$success) {
            return 1;
        }

        // Clear some caches after pushing.
        $this->api()->clearEnvironmentsCache($project->id);
        if ($this->hasSelectedEnvironment()) {
            try {
                $sshUrl = $this->getSelectedEnvironment()->getSshUrl();
                /** @var \Platformsh\Cli\Service\Relationships $relationships */
                $relationships = $this->getService('relationships');
                $relationships->clearCaches($sshUrl);
            } catch (EnvironmentStateException $e) {
                // Ignore environments with a missing SSH URL.
            }
        }

        if ($activate) {
            $args = [
                '--project' => $project->getUri(),
                '--environment' => $target,
                '--parent' => $parentId,
                '--yes' => true,
                '--no-wait' => $input->getOption('no-wait'),
            ];

            return $this->runOtherCommand('environment:activate', $args);
        }

        return 0;
    }

    /**
     * Determines the parent of the target environment (for activate / branch).
     *
     * @param Project          $project
     * @param Environment|null $targetEnvironment
     *
     * @return string The parent environment ID.
     */
    private function findTargetParent(Project $project, Environment $targetEnvironment = null) {
        $environments = $this->api()->getEnvironments($project);
        if ($targetEnvironment && $targetEnvironment->parent) {
            $defaultId = $targetEnvironment->parent;
        } elseif ($this->hasSelectedEnvironment()) {
            $defaultId = $this->getSelectedEnvironment()->id;
        } else {
            $defaultId = $this->api()->getDefaultEnvironmentId($environments);
        }
        if (array_keys($environments) === [$defaultId]) {
            return $defaultId;
        }
        $questionHelper = $this->getService('question_helper');

        return $questionHelper->askInput('Parent environment', $defaultId, array_keys($environments));
    }
}
