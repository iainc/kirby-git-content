<?php

namespace Thathoff\GitContent;

use CzProject\GitPhp\Git;
use CzProject\GitPhp\Runners\CliRunner;
use CzProject\GitPhp\GitException;
use CzProject\GitPhp\GitRepository;
use DateTime;
use Exception;

class KirbyGitHelper
{
    private $kirby;
    private $repo;
    private $repoPath;
    private $commitMessageTemplate;
    private $pullOnChange;
    private $pushOnChange;
    private $commitOnChange;
    private $gitBin;
    private $git;

    public function __construct($repoPath = false)
    {
        $this->kirby = kirby();
        $this->repoPath = $repoPath ? $repoPath : option('thathoff.git-content.path', $this->kirby->root("content"));
        $this->commitMessageTemplate = option('thathoff.git-content.commitMessage', ':action:(:item:): :url:');
    }

    private function initRepo()
    {
        if ($this->repo) {
            return true;
        }

        if (!class_exists("CzProject\GitPhp\Git")) {
            throw new Exception('Git class not found. Make sure you run composer install inside this plugins directory');
        }

        $this->pullOnChange = option('thathoff.git-content.pull', false);
        $this->pushOnChange = option('thathoff.git-content.push', false);
        $this->commitOnChange = option('thathoff.git-content.commit', true);
        $this->gitBin = option('thathoff.git-content.gitBin', '');
        if (!$this->gitBin) {
            $this->gitBin = 'git';
        }
        // force English locale for predictable command outputs
        $runner = new CliRunner('LC_ALL=C ' . $this->gitBin);
        $this->git = new Git($runner);
        $this->repo = $this->git->open($this->repoPath);
    }

    public function log(int $limit = 10)
    {
        $separator = "\|";
        $format = implode($separator, ["%H", "%s", "%an", "%ae", "%cI"]);

		try {
			$log = $this->getRepo()->execute('log', '--pretty=format:' . $format, '--max-count=' . $limit);
		} catch (GitException $e) {
			$this->catchGitException($e);
		}

        $log = array_map(
            function ($line) use ($separator) {
                $entry = explode($separator, $line);

                return [
                    'hash' => $entry[0],
                    'message' => $entry[1],
                    'author' => $entry[2],
                    'email' => $entry[3],
                    'date' => DateTime::createFromFormat(DateTime::ISO8601, $entry[4]),
                ];
            },
            $log
        );

        return $log;
    }

    private function getRepo(): GitRepository
    {
        if ($this->repo == null) {
            $this->initRepo();
        }

        return $this->repo;
    }

    public function commit($commitMessage, $paths, $author = null)
    {
        try {
            if ($paths) {
                $uniquePaths = array_unique($paths);
                $this->getRepo()->execute('add', '--', ...$uniquePaths);
            }

            $this->getRepo()->run(...$this->buildCommitCommand($commitMessage, $author));
        } catch (GitException $e) {
			$this->catchGitException($e);
        }
    }

    private function buildCommitCommand(string $commitMessage, ?array $author): array
    {
        $command = [];

        if ($author !== null) {
            $command[] = '-c';
            $command[] = 'user.name=' . $author['name'];
            $command[] = '-c';
            $command[] = 'user.email=' . $author['email'];
        }

        $command[] = 'commit';

        if ($author) {
            $command[] = '--author=' . $this->formatAuthorString($author);
        }

        $command[] = [
            '-m' => $commitMessage,
        ];

        return $command;
    }

    private function formatAuthorString(array $author): string
    {
        return $author['name'] . " <" . $author['email'] . ">";
    }

	private function catchGitException(GitException $e) {
		// Sometimes a change results in multiple hooks being fired (for example status change). This causes a race condition:
		// As the file change can only be committed once, latter hooks will fail when calling either 'git add' or 'git commit'.
		// The files in question have actually been committed already in an earlier hook call and therefore we may ignore the errors.
		// We don’t run git status in front because that is much slower in large repositories.
		// Refer to #84

		// We concat the actual git error message, the error output and regular output together to then search for "exclusion strings".
		// For some reason, the output is sometimes obtainable using getErrorOutput() and sometimes using getOutput().
		$errorMessage = $e->getMessage();
		if ($runnerResult = $e->getRunnerResult()) {
			$errorMessage .= "\n\n" . implode("\n", $runnerResult->getErrorOutput()) . "\n\n" . implode("\n", $runnerResult->getOutput());
		}

		// make the dubious ownership error more user friendly
		if (strpos($errorMessage, 'dubious ownership') !== false) {
			$phpUser = posix_getpwuid(posix_geteuid());
			$phpUserName = $phpUser['name'];

			throw new Exception('The content repository is not owned by the user running the PHP process. ' .
				'Please change the owner to ' . $phpUserName . ', eg. by running `chown -R ' . $phpUserName . ' "' . $this->repoPath . '"`.'
			);
		}

		$ignoredErrors = [
			'nothing to commit',
			'did not match any files'
		];

		// if the error message is not in the ignored errors, throw the exception
		// check if one of the ignored errors is in the error message
		foreach ($ignoredErrors as $ignoredError) {
			if (strpos($errorMessage, $ignoredError) !== false) {
				return;
			}
		}

		// otherwise throw the exception
		throw $e;
	}

    public function push()
    {
        $this->getRepo()->push();
    }

    public function getCurrentBranch()
    {
        return $this->getRepo()->getCurrentBranchName();
    }

    public function pull()
    {
        $this->getRepo()->pull(null, ['--no-rebase']);
    }

    public function sync(?string $sha = null, ?string $branch = null): string
    {
        $sha = $this->normalizeSyncSha($sha);
        $targetBranch = $this->normalizeSyncBranch($branch);
        $currentBranch = $this->getCurrentBranch();
        $conflictBranch = $this->parseConflictBranch($currentBranch);

        if ($sha !== null && $this->refContainsCommit($currentBranch, $sha)) {
            return 'Skipped sync because ' . $currentBranch . ' already contains ' . $sha . '.';
        }

        if ($targetBranch !== null && $currentBranch !== $targetBranch) {
            if ($conflictBranch !== null) {
                $baseBranch = $conflictBranch['baseBranch'] ?? $targetBranch ?? 'the original branch';
                return 'Skipped sync because conflict branch ' . $currentBranch . ' is active. ' .
                    'Resolve it manually and merge it into ' . $baseBranch . '.';
            }

            return 'Skipped sync because the current branch is ' . $currentBranch .
                ' but the webhook targets ' . $targetBranch . '.';
        }

        if ($conflictBranch !== null) {
            return $this->syncConflictBranch($currentBranch, $conflictBranch);
        }

        $status = $this->status();
        if (count($status['files']) > 0) {
            return 'Skipped sync because the repository has uncommitted changes.';
        }

        $remoteBranch = $this->findUpstreamBranch();
        if ($remoteBranch === null) {
            return $this->publishBranch($currentBranch);
        }

        $this->fetch();

        if ($sha !== null && !$this->refContainsCommit($remoteBranch, $sha)) {
            return 'Skipped sync because ' . $remoteBranch . ' does not contain ' . $sha . '.';
        }

        $status = $this->status();
        $ahead = $status['aheadOfOrigin'] ?? 0;
        $behind = $status['behindOfOrigin'] ?? 0;

        if ($ahead > 0 && $behind > 0) {
            $localChangedFiles = $this->getExclusiveChangedFiles($remoteBranch, $currentBranch);
            $remoteChangedFiles = $this->getExclusiveChangedFiles($currentBranch, $remoteBranch);
            $overlappingFiles = array_values(array_intersect($localChangedFiles, $remoteChangedFiles));

            if (count($overlappingFiles) === 0) {
                $rebaseMessage = $this->rebaseOntoUpstream($currentBranch, $remoteBranch);

                if ($sha !== null) {
                    return $rebaseMessage;
                }

                $pushMessage = $this->pushBranchCommits($currentBranch, $remoteBranch);

                return $pushMessage !== null ? $rebaseMessage . ' ' . $pushMessage : $rebaseMessage;
            }

            return $this->createConflictBranch($currentBranch, $remoteBranch, $overlappingFiles);
        }

        if ($ahead > 0) {
            if ($sha === null) {
                return $this->pushBranchCommits($currentBranch, $remoteBranch)
                    ?? ('Current branch is already up to date with ' . $remoteBranch . '.');
            }

            return 'Skipped sync because ' . $currentBranch . ' is ahead of ' . $remoteBranch .
                ' by ' . $ahead . ' commit' . ($ahead === 1 ? '' : 's') . '.';
        }

        if ($behind === 0) {
            return 'Current branch is already up to date with ' . $remoteBranch . '.';
        }

        $this->getRepo()->execute('merge', '--ff-only', $remoteBranch);

        return 'Fast-forwarded ' . $currentBranch . ' to ' . $remoteBranch .
            ' by ' . $behind . ' commit' . ($behind === 1 ? '' : 's') . '.';
    }

    private function syncConflictBranch(string $branch, array $conflictBranch): string
    {
        $this->fetch();

        $status = $this->status();
        if (count($status['files']) > 0) {
            return 'Skipped sync because the repository has uncommitted changes.';
        }

        if (($status['hasRemote'] ?? false) !== true) {
            return $this->publishConflictBranch($branch, $conflictBranch);
        }

        $remoteBranch = $this->getUpstreamBranch($branch);
        $ahead = $status['aheadOfOrigin'] ?? 0;
        $behind = $status['behindOfOrigin'] ?? 0;

        if ($ahead > 0 && $behind > 0) {
            $localChangedFiles = $this->getExclusiveChangedFiles($remoteBranch, $branch);
            $remoteChangedFiles = $this->getExclusiveChangedFiles($branch, $remoteBranch);
            $overlappingFiles = array_values(array_intersect($localChangedFiles, $remoteChangedFiles));

            if (count($overlappingFiles) === 0) {
                $rebaseMessage = $this->rebaseOntoUpstream($branch, $remoteBranch);
                $pushMessage = $this->pushConflictBranchCommits($branch, $remoteBranch);

                return $pushMessage !== null ? $rebaseMessage . ' ' . $pushMessage : $rebaseMessage;
            }

            return 'Skipped sync because conflict branch ' . $branch . ' and ' . $remoteBranch .
                ' both changed ' . count($overlappingFiles) . ' file' .
                (count($overlappingFiles) === 1 ? '' : 's') . ': ' .
                $this->formatChangedFiles($overlappingFiles) . '. Resolve the branch manually before syncing again.';
        }

        if ($behind > 0) {
            $this->getRepo()->execute('merge', '--ff-only', $remoteBranch);

            return 'Fast-forwarded conflict branch ' . $branch . ' to ' . $remoteBranch .
                ' by ' . $behind . ' commit' . ($behind === 1 ? '' : 's') . '.';
        }

        $pushMessage = $this->pushConflictBranchCommits($branch, $remoteBranch);
        if ($pushMessage !== null) {
            return $pushMessage;
        }

        return 'Conflict branch ' . $branch . ' is already up to date with ' . $remoteBranch . '.';
    }

    public function fetch()
    {
        $this->getRepo()->fetch();
    }

    public function reset()
    {
        $this->getRepo()->execute('reset', '--hard', 'HEAD');
    }

    public function resetToOrigin()
    {
        $remoteBranch = $this->getUpstreamBranch();
        $this->getRepo()->execute('reset', '--hard', $remoteBranch);
    }

    public function removeIndexLock()
    {
        if (!$this->hasIndexLock()) {
            return;
        }

        unlink($this->repoPath . '/.git/index.lock');
    }

    public function hasIndexLock()
    {
        return file_exists($this->repoPath . '/.git/index.lock');
    }

    public function clean()
    {
        $this->getRepo()->execute('clean', '-fd');
    }

    public function addAll()
    {
        $this->getRepo()->addAllChanges();
    }

    public function checkout(string $branch)
    {
        $this->getRepo()->checkout($branch);
    }

    public function getBranches()
    {
        return $this->getRepo()->getLocalBranches();
    }

    public function createBranch(string $branch)
    {
        return $this->getRepo()->createBranch($branch, true);
    }

    public function status() {
        /* git returns a two character code for every entry in 'git status --porcelain'. these codes are shown below, split in index and worktree codes.
           the first code character always refers to the index state of the file, the second for the worktree
           for more info refer to https://git-scm.com/docs/git-status#_short_format
        */
        $currentBranch = $this->getCurrentBranch();
        $upstreamResponse = $this->getRepo()->execute('status',  '--porcelain=2', '--branch');
        $filesResponse = $this->getRepo()->execute('status', '--porcelain');

        // REMOTE INFORMATION --------------
        // the first few lines (length depending on whether remote branch is available) are branch information.
        // line about ahead/behind commits looks as follows:
        // # branch.ab +0 -0
        $hasRemote = false;
        $diff = null;
        $aheadCount = null;
        $behindCount = null;
        $upstreamBranch = null;
        foreach ($upstreamResponse as $key => $line) {
            if (!empty($line) && strpos($line, 'branch.upstream ') !== false) {
                $upstreamBranch = trim(substr($line, strlen('# branch.upstream ')));
                continue;
            }

            if (!empty($line) && strpos($line, 'branch.ab') !== false) {
                $hasRemote = true;

                preg_match('/\+\d+/', $line, $ahead);
                preg_match('/\-\d+/', $line, $behind);
                $aheadCount = isset($ahead[0]) ? (int)substr($ahead[0], 1) : 0;
                $behindCount = isset($behind[0]) ? (int)substr($behind[0], 1) : 0;

                $diff = $aheadCount - $behindCount;
                break;
            }
        }

        // CHANGED FILES -------------------
        // one line per file. line looks like this:
        // XY filename.txt
        $files = [];
        foreach ($filesResponse as $key => $file) {
            $files[$key] = [
                'code' => substr($file, 0, 2),
                'filename' => substr($file, 3)
            ];
        }

        $conflictBranch = $this->parseConflictBranch($currentBranch);

        return [
            'currentBranch' => $currentBranch,
            'hasRemote' => $hasRemote,
            'upstreamBranch' => $upstreamBranch,
            'aheadOfOrigin' => $aheadCount,
            'behindOfOrigin' => $behindCount,
            'diffFromOrigin' => $diff,
            'conflictBranch' => [
                'isActive' => $conflictBranch !== null,
                'name' => $conflictBranch['name'] ?? null,
                'baseBranch' => $conflictBranch['baseBranch'] ?? null,
            ],
            'buttons' => $this->getButtons(),
            'syncDisabledMessage' => option('thathoff.git-content.syncDisabledMessage'),
            'files' => $files,
        ];
    }

    public function getButtons(): array
    {
        $defaultButtons = [
            'revert' => true,
            'reset' => false,
            'commit' => true,
            'pull' => false,
            'sync' => true,
            'push' => true,
            'fetch' => true,
            'createBranch' => true,
            'switchBranch' => true,
        ];

        $configuredButtons = option('thathoff.git-content.buttons', []);
        if (!is_array($configuredButtons)) {
            $configuredButtons = [];
        }

        $normalizedButtons = [];
        foreach ($configuredButtons as $key => $value) {
            if (array_key_exists($key, $defaultButtons)) {
                $normalizedButtons[$key] = (bool)$value;
            }
        }

        $buttons = array_merge($defaultButtons, $normalizedButtons);

        if ($user = $this->kirby->user()) {
            $rolePermissions = $user->role()->permissions();

            foreach ($buttons as $key => $isEnabled) {
                if ($rolePermissions->for('thathoff.git-content', $key, true) === false) {
                    $buttons[$key] = false;
                }
            }
        }

        $disableBranchManagement = (bool)option('thathoff.git-content.disableBranchManagement', false);
        if ($disableBranchManagement) {
            $buttons['createBranch'] = false;
            $buttons['switchBranch'] = false;
        }

        return $buttons;
    }

    public function getAuthorIdentity(): ?array
    {
        if (!$user = $this->kirby->user()) {
            return null;
        }

        $gitName = trim((string)$user->content()->get('gitName')->value());
        $gitEmail = trim((string)$user->content()->get('gitEmail')->value());

        return [
            'name' => $gitName !== '' ? $gitName : (string)$user->name()->or($user->email()),
            'email' => $gitEmail !== '' ? $gitEmail : (string)$user->email(),
        ];
    }

    public function getAuthorString(): ?string
    {
        if (!$author = $this->getAuthorIdentity()) {
            return null;
        }

        return $this->formatAuthorString($author);
    }

    public function kirbyChange($action, $item, $paths, $url = '')
    {
        try {
            $this->initRepo();

            if ($this->pullOnChange) {
                $this->pull();
            }

            if ($this->commitOnChange) {
                $author = $this->getAuthorIdentity();

                $this->commit($this->commitMessage($action, $item, $url), $paths, $author);
            }

            if ($this->pushOnChange) {
                $this->push();
            }
        } catch (Exception $exception) {
            $message = $exception->getMessage();

            // enrich message with more info if we got a GitException
            if ($exception instanceof GitException) {
                if ($runnerResult = $exception->getRunnerResult()) {
                    $message .= "\n\n" . implode("\n", $runnerResult->getErrorOutput());
                }
            }

            // show exceptions by default
            if (option('thathoff.git-content.displayErrors', true)) {
                throw new Exception('Unable to update git: ' . $message);
            }

            error_log('Unable to update git: ' . $message, E_USER_ERROR);
        }
    }

    private function commitMessage($action, $item, $url)
    {
        return strtr($this->commitMessageTemplate, [
            ':action:' => $action,
            ':capitalized-action:' => ucfirst($action),
            ':item:' => $item,
            ':url:' => $url,
        ]);
    }

    private function getUpstreamBranch(?string $branch = null): string
    {
        $remoteBranch = $this->findUpstreamBranch($branch);

        if (empty($remoteBranch)) {
            throw new Exception('No remote branch found. Please add a remote branch first.');
        }

        return $remoteBranch;
    }

    private function findUpstreamBranch(?string $branch = null): ?string
    {
        $branch ??= $this->getCurrentBranch();
        $ref = 'refs/heads/' . $branch;
        $remoteBranch = $this->getRepo()->execute('for-each-ref', '--format=%(upstream:short)', $ref);
        $remoteBranch = $remoteBranch[0] ?? null;
        $remoteBranch = trim((string)$remoteBranch);

        return $remoteBranch !== '' ? $remoteBranch : null;
    }

    private function getExclusiveChangedFiles(string $fromRef, string $toRef): array
    {
        $files = $this->getRepo()->execute('diff', '--name-only', $fromRef . '...' . $toRef);

        $files = array_map('trim', $files);
        $files = array_filter($files, fn (string $file) => $file !== '');
        $files = array_values(array_unique($files));
        sort($files);

        return $files;
    }

    private function rebaseOntoUpstream(string $branch, string $remoteBranch): string
    {
        $commitCount = $this->countRangeCommits($remoteBranch . '..' . $branch);

        try {
            $this->getRepo()->execute(
                '-c',
                'user.name=' . $this->getSyncCommitterName(),
                '-c',
                'user.email=' . $this->getSyncCommitterEmail(),
                'rebase',
                $remoteBranch
            );
        } catch (GitException $exception) {
            $this->abortRebase();
            throw $exception;
        }

        return 'Rebased ' . $commitCount . ' local commit' . ($commitCount === 1 ? '' : 's') .
            ' from ' . $branch . ' onto ' . $remoteBranch . '.';
    }

    private function publishConflictBranch(string $branch, array $conflictBranch): string
    {
        $remoteBranch = $this->publishBranch($branch, $this->resolveConflictBranchRemoteName($conflictBranch));

        return 'Pushed conflict branch ' . $branch . ' to ' . $remoteBranch . ' and set upstream.';
    }

    private function pushConflictBranchCommits(string $branch, string $remoteBranch): ?string
    {
        $ahead = $this->countRangeCommits($remoteBranch . '..' . $branch);
        if ($ahead === 0) {
            return null;
        }

        $remoteName = $this->extractRemoteName($remoteBranch);
        $this->pushBranchToRemote($branch, $remoteName);

        return 'Pushed ' . $ahead . ' commit' . ($ahead === 1 ? '' : 's') . ' from conflict branch ' .
            $branch . ' to ' . $remoteBranch . '.';
    }

    private function pushBranchCommits(string $branch, string $remoteBranch): ?string
    {
        $ahead = $this->countRangeCommits($remoteBranch . '..' . $branch);
        if ($ahead === 0) {
            return null;
        }

        $remoteName = $this->extractRemoteName($remoteBranch);
        $this->pushBranchToRemote($branch, $remoteName);

        return 'Pushed ' . $ahead . ' commit' . ($ahead === 1 ? '' : 's') . ' from ' .
            $branch . ' to ' . $remoteBranch . '.';
    }

    private function pushBranchToRemote(string $branch, string $remoteName, bool $setUpstream = false): void
    {
        $arguments = ['push'];

        if ($setUpstream) {
            $arguments[] = '--set-upstream';
        }

        $arguments[] = $remoteName;
        $arguments[] = $branch . ':refs/heads/' . $branch;

        $this->getRepo()->execute(...$arguments);
    }

    private function publishBranch(string $branch, ?string $remoteName = null): string
    {
        $remoteName ??= $this->getPreferredRemoteName();
        $remoteBranch = $remoteName . '/' . $branch;
        $this->pushBranchToRemote($branch, $remoteName, true);

        return $remoteBranch;
    }

    private function abortRebase(): void
    {
        try {
            $this->getRepo()->execute('rebase', '--abort');
        } catch (GitException) {
            // Ignore abort failures when no rebase is active.
        }
    }

    private function createConflictBranch(string $branch, string $remoteBranch, array $overlappingFiles): string
    {
        $remoteName = $this->extractRemoteName($remoteBranch);
        $conflictBranch = $this->buildUniqueConflictBranchName($branch, $remoteName);
        $pushError = null;

        $this->getRepo()->execute('branch', $conflictBranch, 'HEAD');
        $this->setConflictBranchBaseBranch($conflictBranch, $branch);

        try {
            $this->pushBranchToRemote($conflictBranch, $remoteName, true);
        } catch (GitException $exception) {
            $pushError = $this->formatGitExceptionMessage($exception);
        }

        $this->checkout($conflictBranch);

        $this->getRepo()->execute('branch', '-f', $branch, $remoteBranch);

        $message = 'Created conflict branch ' . $conflictBranch . ' because ' . $branch . ' and ' .
            $remoteBranch . ' both changed ' . count($overlappingFiles) . ' file' .
            (count($overlappingFiles) === 1 ? '' : 's') . ': ' .
            $this->formatChangedFiles($overlappingFiles) . '. Resolve it manually and merge it into ' .
            $branch . '.';

        if ($pushError !== null) {
            return $message . ' The branch was not pushed automatically: ' . $pushError;
        }

        return $message;
    }

    private function buildUniqueConflictBranchName(string $baseBranch, string $remoteName): string
    {
        $baseName = $this->getConflictBranchPrefix() . '/' . gmdate('Ymd-Hi');
        $candidate = $baseName;
        $suffix = 2;

        while (
            $this->refExists('refs/heads/' . $candidate) ||
            $this->refExists('refs/remotes/' . $remoteName . '/' . $candidate)
        ) {
            $candidate = $baseName . '-' . $suffix;
            $suffix++;
        }

        return $candidate;
    }

    private function getShortCommitHash(string $ref): string
    {
        $hash = $this->getRepo()->execute('rev-parse', '--short=12', $ref);

        return trim($hash[0] ?? '');
    }

    private function refExists(string $ref): bool
    {
        try {
            $this->getRepo()->execute('show-ref', '--verify', '--quiet', $ref);
            return true;
        } catch (GitException) {
            return false;
        }
    }

    private function extractRemoteName(string $remoteBranch): string
    {
        $parts = explode('/', $remoteBranch, 2);

        return $parts[0];
    }

    private function getConflictBranchPrefix(): string
    {
        $prefix = trim((string)option('thathoff.git-content.syncConflictBranchPrefix', 'conflict'), '/');

        return $prefix !== '' ? $prefix : 'conflict';
    }

    private function parseConflictBranch(string $branch): ?array
    {
        $prefix = $this->getConflictBranchPrefix();
        if ($branch === $prefix || strpos($branch, $prefix . '/') !== 0) {
            return null;
        }

        return [
            'name' => $branch,
            'baseBranch' => $this->getConflictBranchBaseBranch($branch),
        ];
    }

    private function setConflictBranchBaseBranch(string $branch, string $baseBranch): void
    {
        $this->getRepo()->execute('config', 'branch.' . $branch . '.ia-base-branch', $baseBranch);
    }

    private function getConflictBranchBaseBranch(string $branch): ?string
    {
        try {
            $baseBranch = $this->getRepo()->execute('config', '--get', 'branch.' . $branch . '.ia-base-branch');
        } catch (GitException) {
            return null;
        }

        $baseBranch = trim((string)($baseBranch[0] ?? ''));

        return $baseBranch !== '' ? $baseBranch : null;
    }

    private function resolveConflictBranchRemoteName(array $conflictBranch): string
    {
        $baseBranch = $conflictBranch['baseBranch'] ?? null;

        if ($baseBranch !== null) {
            $baseRemoteBranch = $this->findUpstreamBranch($baseBranch);
            if ($baseRemoteBranch !== null) {
                return $this->extractRemoteName($baseRemoteBranch);
            }
        }

        return $this->getPreferredRemoteName();
    }

    private function getPreferredRemoteName(): string
    {
        $remotes = $this->getRepo()->execute('remote');
        $remotes = array_map('trim', $remotes);
        $remotes = array_values(array_filter($remotes, fn (string $remote) => $remote !== ''));

        if (in_array('origin', $remotes, true)) {
            return 'origin';
        }

        if (isset($remotes[0])) {
            return $remotes[0];
        }

        throw new Exception('No remote found. Please add a remote first.');
    }

    private function countRangeCommits(string $range): int
    {
        $response = $this->getRepo()->execute('rev-list', '--count', $range);

        return (int)($response[0] ?? 0);
    }

    private function getSyncCommitterName(): string
    {
        $name = trim((string)option('thathoff.git-content.syncCommitterName', 'Git Content Sync'));

        return $name !== '' ? $name : 'Git Content Sync';
    }

    private function getSyncCommitterEmail(): string
    {
        $email = trim((string)option('thathoff.git-content.syncCommitterEmail', 'git-content-sync@localhost'));

        return $email !== '' ? $email : 'git-content-sync@localhost';
    }

    private function formatChangedFiles(array $files): string
    {
        $preview = array_slice($files, 0, 3);
        $message = implode(', ', $preview);
        $remaining = count($files) - count($preview);

        if ($remaining > 0) {
            $message .= ' +' . $remaining . ' more';
        }

        return $message;
    }

    private function normalizeSyncSha(?string $sha): ?string
    {
        if ($sha === null) {
            return null;
        }

        $sha = trim($sha);
        if ($sha === '') {
            return null;
        }

        if (preg_match('/^[0-9a-f]{7,40}$/i', $sha) !== 1) {
            throw new Exception('Invalid sha parameter.');
        }

        return strtolower($sha);
    }

    private function normalizeSyncBranch(?string $branch): ?string
    {
        if ($branch === null) {
            return null;
        }

        $branch = trim($branch);
        if ($branch === '') {
            return null;
        }

        try {
            $this->getRepo()->execute('check-ref-format', '--branch', $branch);
        } catch (GitException) {
            throw new Exception('Invalid branch parameter.');
        }

        return $branch;
    }

    private function refContainsCommit(string $ref, string $sha): bool
    {
        if (!$this->repoHasCommit($sha)) {
            return false;
        }

        $refs = $this->getRepo()->execute('branch', '-a', '--format=%(refname:short)', '--contains', $sha);

        foreach ($refs as $candidate) {
            if (trim($candidate) === $ref) {
                return true;
            }
        }

        return false;
    }

    private function repoHasCommit(string $sha): bool
    {
        try {
            $this->getRepo()->execute('rev-parse', '--verify', '--quiet', $sha . '^{commit}');
            return true;
        } catch (GitException) {
            return false;
        }
    }

    private function formatGitExceptionMessage(GitException $exception): string
    {
        $message = $exception->getMessage();
        if ($runnerResult = $exception->getRunnerResult()) {
            $errorOutput = implode("\n", $runnerResult->getErrorOutput());
            $output = implode("\n", $runnerResult->getOutput());
            $message = trim($message . "\n" . $errorOutput . "\n" . $output);
        }

        return $message;
    }
}
