import http from 'node:http';
import type {AddressInfo} from 'node:net';
import type {Logger} from './logger.js';
import type {OrchestratorSnapshot} from './orchestrator.js';

interface SymphonyStateServerArgs {
    enabled: boolean;
    logger: Logger;
    host: string;
    port: number;
    getSnapshot: () => OrchestratorSnapshot;
    refresh: () => Promise<void>;
}

export class SymphonyStateServer {
    private readonly enabled: boolean;
    private readonly logger: Logger;
    private readonly host: string;
    private readonly port: number;
    private readonly getSnapshot: () => OrchestratorSnapshot;
    private readonly refresh: () => Promise<void>;
    private server?: http.Server;
    private listeningPort?: number;

    public constructor(args: SymphonyStateServerArgs) {
        this.enabled = args.enabled;
        this.logger = args.logger;
        this.host = args.host;
        this.port = args.port;
        this.getSnapshot = args.getSnapshot;
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

            if (method === 'GET' && pathname === '/api/v1/state') {
                this.respondJson(response, 200, {
                    status: 'ok',
                    snapshot: this.getSnapshot(),
                });
                return;
            }

            if (method === 'POST' && pathname === '/api/v1/refresh') {
                void this.refresh().catch((error) => {
                    const message = error instanceof Error ? error.message : String(error);
                    this.logger.error('State API refresh failed', {error: message});
                });

                this.respondJson(response, 202, {
                    status: 'accepted',
                });
                return;
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
}
