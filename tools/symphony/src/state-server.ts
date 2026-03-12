import http from 'node:http';
import type {AddressInfo} from 'node:net';
import type {Logger} from './logger.js';
import type {OrchestratorSnapshot} from './orchestrator.js';
import {renderDashboard} from './state-dashboard.js';

interface SymphonyStateServerArgs {
    enabled: boolean;
    logger: Logger;
    host: string;
    port: number;
    getSnapshot: () => OrchestratorSnapshot;
    getIssueDetails: (issueIdentifier: string) => Record<string, unknown> | undefined;
    refresh: () => Promise<void>;
}

export class SymphonyStateServer {
    private readonly enabled: boolean;
    private readonly logger: Logger;
    private readonly host: string;
    private readonly port: number;
    private readonly getSnapshot: () => OrchestratorSnapshot;
    private readonly getIssueDetails: (issueIdentifier: string) => Record<string, unknown> | undefined;
    private readonly refresh: () => Promise<void>;
    private server?: http.Server;
    private listeningPort?: number;

    public constructor(args: SymphonyStateServerArgs) {
        this.enabled = args.enabled;
        this.logger = args.logger;
        this.host = args.host;
        this.port = args.port;
        this.getSnapshot = args.getSnapshot;
        this.getIssueDetails = args.getIssueDetails;
        this.refresh = args.refresh;
    }

    public isEnabled(): boolean {
        return this.enabled;
    }

    public getListeningPort(): number | undefined {
        return this.listeningPort;
    }

    public async start(): Promise<void> {
        if (!this.enabled || this.server) {
            return;
        }

        this.server = http.createServer((request, response) => {
            void this.handleRequest(request, response);
        });

        await new Promise<void>((resolve, reject) => {
            this.server?.once('error', reject);
            this.server?.listen(this.port, this.host, () => {
                resolve();
            });
        });

        const serverAddress = this.server.address();
        this.listeningPort = typeof serverAddress === 'string' ? this.port : (serverAddress as AddressInfo).port;

        this.logger.info('Symphony state API started', {
            host: this.host,
            port: this.listeningPort,
        });
    }

    public async stop(): Promise<void> {
        if (!this.server) {
            return;
        }

        await new Promise<void>((resolve) => {
            this.server?.close(() => resolve());
        });

        this.logger.info('Symphony state API stopped', {
            host: this.host,
            port: this.listeningPort,
        });

        this.server = undefined;
        this.listeningPort = undefined;
    }

    private async handleRequest(request: http.IncomingMessage, response: http.ServerResponse): Promise<void> {
        try {
            const method = request.method ?? 'GET';
            const requestUrl = request.url ?? '/';
            const {pathname} = new URL(requestUrl, `http://${this.host}:${this.port}`);

            if (pathname === '/') {
                if (method !== 'GET') {
                    this.respondJson(response, 405, {
                        status: 'method_not_allowed',
                    });
                    return;
                }

                this.respondHtml(response, 200, renderDashboard(this.getSnapshot()));
                return;
            }

            if (pathname === '/api/v1/state') {
                if (method !== 'GET') {
                    this.respondJson(response, 405, {
                        status: 'method_not_allowed',
                    });
                    return;
                }

                this.respondJson(response, 200, {
                    status: 'ok',
                    snapshot: this.getSnapshot(),
                });
                return;
            }

            if (pathname === '/api/v1/refresh') {
                if (method !== 'POST') {
                    this.respondJson(response, 405, {
                        status: 'method_not_allowed',
                    });
                    return;
                }

                void this.refresh().catch((error) => {
                    const message = error instanceof Error ? error.message : String(error);
                    this.logger.error('State API refresh failed', {error: message});
                });

                this.respondJson(response, 202, {
                    status: 'accepted',
                });
                return;
            }

            if (pathname.startsWith('/api/v1/')) {
                if (method !== 'GET') {
                    this.respondJson(response, 405, {
                        status: 'method_not_allowed',
                    });
                    return;
                }

                const issueIdentifier = decodeURIComponent(pathname.slice('/api/v1/'.length));
                if (issueIdentifier.length > 0) {
                    const issueDetails = this.getIssueDetails(issueIdentifier);
                    if (!issueDetails) {
                        this.respondJson(response, 404, {
                            status: 'not_found',
                        });
                        return;
                    }

                    this.respondJson(response, 200, issueDetails);
                    return;
                }
            }

            this.respondJson(response, 404, {
                status: 'not_found',
            });
        } catch (error) {
            const message = error instanceof Error ? error.message : String(error);
            this.logger.error('State API request handling failed', {error: message});
            this.respondJson(response, 500, {
                status: 'error',
            });
        }
    }

    private respondJson(response: http.ServerResponse, statusCode: number, payload: Record<string, unknown>): void {
        response.statusCode = statusCode;
        response.setHeader('Content-Type', 'application/json; charset=utf-8');
        response.end(JSON.stringify(payload));
    }

    private respondHtml(response: http.ServerResponse, statusCode: number, payload: string): void {
        response.statusCode = statusCode;
        response.setHeader('Content-Type', 'text/html; charset=utf-8');
        response.end(payload);
    }
}
