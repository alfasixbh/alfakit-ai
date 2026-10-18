<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Mcp\Tools;

use AlfaSix\Alfakit\Resources\Resource;
use AlfaSix\AlfakitAi\Mcp\McpAccess;
use AlfaSix\AlfakitAi\Models\ToolCall;
use AlfaSix\AlfakitAi\Support\PanelGateway;
use AlfaSix\AlfakitAi\Support\ToolAudit;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * An MCP tool acting as the token's user. Each call is checked against the token's
 * abilities and the user's policies, and written to the audit log.
 */
abstract class McpTool extends Tool
{
    /**
     * @param  array<string, mixed>  $arguments
     * @return array{0: string, 1: Response}
     */
    abstract protected function run(PanelGateway $gateway, McpAccess $access, array $arguments): array;

    public function handle(Request $request): Response
    {
        $arguments = $request->all();
        $gateway = PanelGateway::current();
        $access = app()->bound(McpAccess::class) ? app(McpAccess::class) : null;

        if ($gateway === null || ! $access instanceof McpAccess) {
            return Response::error('Unauthenticated.');
        }

        try {
            [$status, $response] = $this->run($gateway, $access, $arguments);
        } catch (ValidationException $exception) {
            [$status, $response] = [ToolCall::FAILED, Response::error('Validation failed: '.json_encode($exception->errors(), JSON_UNESCAPED_UNICODE))];
        } catch (HttpExceptionInterface $exception) {
            [$status, $response] = [ToolCall::DENIED, Response::error($exception->getStatusCode() === 403 ? 'Forbidden.' : 'The request could not be completed.')];
        } catch (Throwable $exception) {
            report($exception);
            [$status, $response] = [ToolCall::FAILED, Response::error('The tool failed.')];
        }

        ToolAudit::record(ToolAudit::MCP, $this->name(), $arguments, $status, $response->isError() ? 'error' : null);

        return $response;
    }

    /**
     * The resource for `$slug` if the user may list it and the token may `$operation` it.
     *
     * @return class-string<resource>|null
     */
    protected function resource(PanelGateway $gateway, McpAccess $access, mixed $slug, string $operation): ?string
    {
        $slug = is_scalar($slug) ? (string) $slug : '';
        $resource = $gateway->resource($slug);

        return $resource !== null && $access->allows($slug, $operation) ? $resource : null;
    }

    /**
     * @return array{0: string, 1: Response}
     */
    protected function denied(string $message = 'Unknown resource or record, or not allowed.'): array
    {
        return [ToolCall::DENIED, Response::error($message)];
    }
}
