import fs from 'node:fs';
import path from 'node:path';

export type IntegrationTool = {
  slug: string;
  function_name: string;
  name: string;
  type: string;
  description: string;
  short_description: string;
  parameters: Record<string, Record<string, unknown>>;
  parameter_count: number;
};

export type IntegrationCredential = {
  key: string;
  type: string;
  label: string;
  required: boolean;
  hint?: string;
  placeholder?: string;
};

export type Integration = {
  slug: string;
  package: string;
  route_slug: string;
  name: string;
  description: string;
  short_description: string;
  category: string;
  docs_url?: string | null;
  auth_strategy: string;
  auth_strategy_label: string;
  auth_summary: string;
  compatibility_summary: string;
  cli_setup_supported: boolean;
  cli_runtime_supported: boolean;
  tools: IntegrationTool[];
  credentials: IntegrationCredential[];
  runtime_requirements: Array<Record<string, unknown>>;
  related_integrations: Array<{ slug: string; name: string; route_slug: string }>;
  lua_docs?: string | null;
  read_tool_count: number;
  write_tool_count: number;
  setup: {
    required_credentials: string[];
    env_vars: string[];
    cli_configure_command: string;
    doctor_command: string;
    status_command: string;
    mcp_gateway_install_command: string;
    mcp_gateway_serve_command: string;
    cli_setup_summary: string;
    mcp_setup_summary: string;
    supports_multi_account: boolean;
  };
  seo: {
    page_title: string;
    meta_description: string;
    h1: string;
    auth_strategy: string;
    auth_summary: string;
    cli_setup_supported: boolean;
    cli_runtime_supported: boolean;
    mcp_gateway_supported: boolean;
    lua_supported: boolean;
    cli_setup_summary: string;
    mcp_setup_summary: string;
    keywords: string[];
  };
};

type CatalogShape = {
  integrations?: Array<Record<string, unknown>>;
};

const catalogPathCandidates = [
  path.resolve(process.cwd(), '../integrations/integrations-catalog.json'),
  path.resolve(process.cwd(), 'integrations/integrations-catalog.json'),
  path.resolve(process.cwd(), '../../integrations/integrations-catalog.json'),
];

function readCatalog(): CatalogShape {
  const catalogPath = catalogPathCandidates.find((candidate) => fs.existsSync(candidate));
  if (!catalogPath) {
    throw new Error('Could not find integrations/integrations-catalog.json for website generation.');
  }

  return JSON.parse(fs.readFileSync(catalogPath, 'utf8')) as CatalogShape;
}

function text(value: unknown, fallback = ''): string {
  return typeof value === 'string' && value.trim() !== '' ? value : fallback;
}

function bool(value: unknown, fallback = false): boolean {
  return typeof value === 'boolean' ? value : fallback;
}

function list<T>(value: unknown): T[] {
  return Array.isArray(value) ? (value as T[]) : [];
}

function object(value: unknown): Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value) ? value as Record<string, unknown> : {};
}

function routeSlug(raw: Record<string, unknown>): string {
  const slug = text(raw.route_slug, text(raw.slug, 'integration'));
  return slug.replace(/^\/+|\/+$/g, '');
}

function envVarName(slug: string, key: string): string {
  return `${slug}_${key}`.replace(/[^A-Za-z0-9]+/g, '_').replace(/^_+|_+$/g, '').toUpperCase();
}

function normalizeTool(raw: Record<string, unknown>): IntegrationTool {
  return {
    slug: text(raw.slug, text(raw.function_name, 'tool')),
    function_name: text(raw.function_name, text(raw.slug, 'tool')),
    name: text(raw.name, text(raw.slug, 'Tool')),
    type: text(raw.type, 'read'),
    description: text(raw.description, text(raw.short_description)),
    short_description: text(raw.short_description),
    parameters: object(raw.parameters) as Record<string, Record<string, unknown>>,
    parameter_count: Number(raw.parameter_count ?? Object.keys(object(raw.parameters)).length),
  };
}

function normalizeCredential(raw: Record<string, unknown>): IntegrationCredential {
  return {
    key: text(raw.key),
    type: text(raw.type, 'string'),
    label: text(raw.label, text(raw.key, 'Credential')),
    required: bool(raw.required, true),
    hint: text(raw.hint) || undefined,
    placeholder: text(raw.placeholder) || undefined,
  };
}

function normalizeIntegration(raw: Record<string, unknown>): Integration {
  const slug = text(raw.slug, 'integration');
  const name = text(raw.name, slug);
  const route = routeSlug(raw);
  const setupRaw = object(raw.setup);
  const seoRaw = object(raw.seo);
  const credentials = list<Record<string, unknown>>(raw.credentials).map(normalizeCredential).filter((field) => field.key !== '');
  const tools = list<Record<string, unknown>>(raw.tools).map(normalizeTool);
  const requiredCredentials = list<string>(setupRaw.required_credentials);
  const envVars = list<string>(setupRaw.env_vars);
  const cliSetupSupported = bool(raw.cli_setup_supported, bool(seoRaw.cli_setup_supported, true));
  const cliRuntimeSupported = bool(raw.cli_runtime_supported, bool(seoRaw.cli_runtime_supported, true));

  const configureCommand = text(setupRaw.cli_configure_command)
    || `kosmo integrations:configure ${slug}${credentials.filter((field) => field.required).map((field) => ` --set ${field.key}="$${envVarName(slug, field.key)}"`).join('')} --enable --read allow --write ask --json`;

  return {
    slug,
    package: text(raw.package, slug),
    route_slug: route,
    name,
    description: text(raw.description, text(raw.short_description, `${name} integration tools for KosmoKrator.`)),
    short_description: text(raw.short_description, text(raw.description)),
    category: text(raw.category, 'other'),
    docs_url: text(raw.docs_url) || null,
    auth_strategy: text(raw.auth_strategy, text(seoRaw.auth_strategy, 'unknown')),
    auth_strategy_label: authStrategyLabel(text(raw.auth_strategy, text(seoRaw.auth_strategy, 'unknown'))),
    auth_summary: text(raw.auth_summary, text(seoRaw.auth_summary, text(raw.compatibility_summary))),
    compatibility_summary: text(raw.compatibility_summary, text(seoRaw.auth_summary)),
    cli_setup_supported: cliSetupSupported,
    cli_runtime_supported: cliRuntimeSupported,
    tools,
    credentials,
    runtime_requirements: list<Record<string, unknown>>(raw.runtime_requirements),
    related_integrations: list<{ slug: string; name: string; route_slug: string }>(raw.related_integrations),
    lua_docs: text(raw.lua_docs) || null,
    read_tool_count: Number(raw.read_tool_count ?? tools.filter((tool) => tool.type === 'read').length),
    write_tool_count: Number(raw.write_tool_count ?? tools.filter((tool) => tool.type === 'write').length),
    setup: {
      required_credentials: requiredCredentials.length > 0 ? requiredCredentials : credentials.filter((field) => field.required).map((field) => field.key),
      env_vars: envVars.length > 0 ? envVars : credentials.map((field) => envVarName(slug, field.key)),
      cli_configure_command: configureCommand,
      doctor_command: text(setupRaw.doctor_command, `kosmo integrations:doctor ${slug} --json`),
      status_command: text(setupRaw.status_command, 'kosmo integrations:status --json'),
      mcp_gateway_install_command: text(setupRaw.mcp_gateway_install_command, `kosmo mcp:gateway:install --integration=${slug} --write=deny --json`),
      mcp_gateway_serve_command: text(setupRaw.mcp_gateway_serve_command, `kosmo mcp:serve --integration=${slug} --write=deny`),
      cli_setup_summary: text(setupRaw.cli_setup_summary, text(seoRaw.cli_setup_summary)),
      mcp_setup_summary: text(setupRaw.mcp_setup_summary, text(seoRaw.mcp_setup_summary)),
      supports_multi_account: bool(setupRaw.supports_multi_account),
    },
    seo: {
      page_title: text(seoRaw.page_title, `${name} CLI and MCP integration for KosmoKrator`),
      meta_description: text(seoRaw.meta_description, `${name} CLI, MCP gateway, and Lua integration documentation for KosmoKrator agents.`),
      h1: text(seoRaw.h1, `${name} Integration`),
      auth_strategy: text(seoRaw.auth_strategy, text(raw.auth_strategy, 'unknown')),
      auth_summary: text(seoRaw.auth_summary, text(raw.compatibility_summary)),
      cli_setup_supported: cliSetupSupported,
      cli_runtime_supported: cliRuntimeSupported,
      mcp_gateway_supported: bool(seoRaw.mcp_gateway_supported, cliRuntimeSupported),
      lua_supported: bool(seoRaw.lua_supported, cliRuntimeSupported),
      cli_setup_summary: text(seoRaw.cli_setup_summary),
      mcp_setup_summary: text(seoRaw.mcp_setup_summary),
      keywords: list<string>(seoRaw.keywords),
    },
  };
}

const integrations = readCatalog()
  .integrations
  ?.map(normalizeIntegration)
  .sort((a, b) => a.name.localeCompare(b.name)) ?? [];

export function getIntegrations(): Integration[] {
  return integrations;
}

export function integrationUrl(integration: Integration, section?: 'lua' | 'cli' | 'mcp'): string {
  return `/integrations/${integration.route_slug}${section ? `/${section}` : ''}`;
}

export function toolFullName(integration: Integration, tool: IntegrationTool): string {
  return `${integration.slug}.${tool.function_name}`;
}

export function authStrategyLabel(strategy: string): string {
  const labels: Record<string, string> = {
    none: 'No credentials',
    api_key: 'API key',
    api_token: 'API token',
    bearer_token: 'Bearer token',
    oauth: 'OAuth',
    oauth2_authorization_code: 'OAuth browser flow',
    oauth2_manual_token: 'Manual OAuth token',
    oauth2_client_credentials: 'OAuth client credentials',
    manual_credentials: 'Manual credentials',
    basic: 'Username and password',
  };

  return labels[strategy] ?? strategy.replace(/_/g, ' ');
}

export function credentialTypeLabel(type: string): string {
  const labels: Record<string, string> = {
    secret: 'Secret',
    string: 'Text',
    text: 'Text',
    url: 'URL',
    oauth: 'OAuth token',
    oauth_connect: 'OAuth connection',
    select: 'Select',
    string_list: 'Text list',
  };

  return labels[type] ?? type.replace(/_/g, ' ');
}

export function operationLabel(type: string): string {
  return type === 'write' ? 'Write' : type === 'read' ? 'Read' : type;
}

export function toolMcpName(integration: Integration, tool: IntegrationTool): string {
  return `integration__${integration.slug.replace(/[^A-Za-z0-9]+/g, '_')}__${tool.function_name}`;
}

export function jsonExampleForTool(tool: IntegrationTool): string {
  const args: Record<string, string | number | boolean> = {};
  for (const [name, schema] of Object.entries(tool.parameters).slice(0, 8)) {
    const type = text(schema?.type, 'string');
    args[name] = type === 'integer' || type === 'number'
      ? 1
      : type === 'boolean'
        ? true
        : `example_${name}`;
  }

  return JSON.stringify(args, null, 2);
}

export function luaExampleForTool(integration: Integration, tool: IntegrationTool): string {
  const payload = jsonExampleForTool(tool)
    .replace(/"([^"]+)":/g, '$1 =')
    .replace(/"/g, '"');

  return `local result = app.integrations.${integration.slug.replace(/-/g, '_')}.${tool.function_name}(${payload})\nprint(result)`;
}
