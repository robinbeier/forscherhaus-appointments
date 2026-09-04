<?php

declare(strict_types=1);

final class GithubPrWriteApplication
{
    private readonly Closure $ghBinaryResolver;
    private readonly Closure $ghRuntimeFactory;
    private readonly ?Closure $localTargetResolver;

    public function __construct(
        private readonly GithubPrWriteProcessRunner $processRunner,
        private readonly GithubPrWriteTarget $target,
        callable $ghBinaryResolver,
        callable $ghRuntimeFactory,
        ?callable $localTargetResolver = null,
    ) {
        $this->ghBinaryResolver = Closure::fromCallable($ghBinaryResolver);
        $this->ghRuntimeFactory = Closure::fromCallable($ghRuntimeFactory);
        $this->localTargetResolver = $localTargetResolver === null ? null : Closure::fromCallable($localTargetResolver);
    }

    /**
     * @param list<string> $arguments
     * @param resource $inputStream
     */
    public function run(array $arguments, $inputStream): int
    {
        try {
            $options = GithubPrWriteRequest::parseArguments($arguments);
            if (!is_resource($inputStream)) {
                throw new InvalidArgumentException('JSON input stream is unavailable.');
            }
            $requestInput = stream_get_contents($inputStream, GITHUB_PR_WRITE_MAX_INPUT_BYTES + 1);
            if (!is_string($requestInput)) {
                throw new InvalidArgumentException('JSON input could not be read.');
            }
            $payload = GithubPrWriteRequest::parsePayload($options['operation'], $requestInput);
            $ghBinary = ($this->ghBinaryResolver)();
            if (!is_string($ghBinary)) {
                throw new RuntimeException('GitHub CLI resolver returned an invalid executable.');
            }
            $this->execute($options, $payload, $ghBinary);
            return 0;
        } catch (InvalidArgumentException $exception) {
            fwrite(STDERR, 'Input rejected: ' . $exception->getMessage() . PHP_EOL);
            return 2;
        } catch (UnexpectedValueException $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);
            return 3;
        } catch (Throwable $exception) {
            fwrite(STDERR, $exception->getMessage() . PHP_EOL);
            return 4;
        }
    }

    /**
     * @param array{operation: string, repo: string, number: int} $options
     * @param array{title?: string, body?: string} $payload
     */
    private function execute(array $options, array $payload, string $ghBinary): void
    {
        $runtime = ($this->ghRuntimeFactory)($ghBinary);
        if (
            !is_array($runtime) ||
            !is_array($runtime['environment'] ?? null) ||
            !is_string($runtime['config_dir'] ?? null) ||
            !is_string($runtime['gh_binary'] ?? null)
        ) {
            throw new RuntimeException('Private GitHub CLI runtime is invalid.');
        }

        try {
            $this->executeWithEnvironment($options, $payload, $runtime['gh_binary'], $runtime['environment']);
        } finally {
            GithubPrWriteRuntime::remove($runtime['config_dir'], $runtime['gh_binary']);
        }
    }

    /**
     * @param array{operation: string, repo: string, number: int} $options
     * @param array{title?: string, body?: string} $payload
     * @param array<string, string> $environment
     */
    private function executeWithEnvironment(array $options, array $payload, string $ghBinary, array $environment): void
    {
        $auth = $this->processRunner->run([$ghBinary, 'auth', 'status', '--hostname', 'github.com'], '', $environment);
        if ($auth['exit_code'] !== 0) {
            throw new UnexpectedValueException('Native GitHub authentication is unavailable.');
        }

        $localTargetResolver = $this->localTargetResolver ?? fn(): array => $this->target->resolveLocal();
        $localTarget = $localTargetResolver();
        $this->target->verifyRemote(
            $ghBinary,
            $options['repo'],
            $options['number'],
            $localTarget['sha'],
            $localTarget['branch'],
            [],
            $environment,
        );

        $isUpdate = $options['operation'] === 'update-pr';
        $method = $isUpdate ? 'PATCH' : 'POST';
        $endpoint = $isUpdate
            ? 'repos/' . $options['repo'] . '/pulls/' . $options['number']
            : 'repos/' . $options['repo'] . '/issues/' . $options['number'] . '/comments';

        try {
            $input = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        } catch (JsonException) {
            throw new InvalidArgumentException('Payload could not be encoded safely.');
        }

        try {
            $writeCommand = [
                $ghBinary,
                'api',
                '--hostname',
                'github.com',
                '--method',
                $method,
                $endpoint,
                '--input',
                '-',
            ];
            if ($isUpdate) {
                $writeCommand[] = '--silent';
            } else {
                $writeCommand[] = '--jq';
                $writeCommand[] = GITHUB_PR_WRITE_COMMENT_ID_PROJECTION;
            }
            $result = $this->processRunner->run($writeCommand, $input, $environment);
        } catch (Throwable) {
            // Once the write child has been invoked, even a local transport
            // failure cannot prove that GitHub did not apply the mutation.
            $result = ['exit_code' => 1, 'stdout' => '', 'stderr' => ''];
        }

        $commentId = $isUpdate ? null : self::extractCommentId($result['stdout']);
        $status = $result['exit_code'] === 0 ? 'ok' : 'write_completed_target_unverified';
        try {
            $postWriteLocalTarget = $localTargetResolver();
            $remoteTarget = $this->target->verifyRemote(
                $ghBinary,
                $options['repo'],
                $options['number'],
                $postWriteLocalTarget['sha'],
                $postWriteLocalTarget['branch'],
                $isUpdate ? array_keys($payload) : [],
                $environment,
            );
            if ($postWriteLocalTarget !== $localTarget) {
                throw new UnexpectedValueException('Canonical local target changed during the GitHub write.');
            }
            if ($isUpdate) {
                $this->target->verifyUpdatedFields($remoteTarget, $payload);
            } elseif ($commentId === null || !isset($payload['body'])) {
                throw new UnexpectedValueException('GitHub comment identifier could not be verified.');
            } else {
                $this->target->verifyCreatedComment(
                    $ghBinary,
                    $options['repo'],
                    $options['number'],
                    $commentId,
                    $payload['body'],
                    $environment,
                );
            }
        } catch (Throwable) {
            // The unsafe REST write has already completed. A non-zero exit here
            // would invite a retry that can duplicate a comment or overwrite PR metadata.
            $status = 'write_completed_target_unverified';
        }

        $response = [
            'status' => $status,
            'operation' => $options['operation'],
            'number' => $options['number'],
        ];
        if ($commentId !== null) {
            $response['comment_id'] = $commentId;
        }

        echo json_encode($response, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    private static function extractCommentId(string $response): ?int
    {
        try {
            $record = json_decode($response, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $commentId = is_array($record) ? $record['id'] ?? null : null;
        return is_int($commentId) && $commentId > 0 ? $commentId : null;
    }
}
