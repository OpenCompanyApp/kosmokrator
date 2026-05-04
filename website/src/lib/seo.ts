import type { Integration } from './integrations-catalog';

export const SITE_URL = 'https://kosmokrator.dev';
export const DEFAULT_OG_IMAGE = `${SITE_URL}/og/default.svg`;

export type MatrixClient = {
  slug: string;
  label: string;
  shortLabel: string;
  category: 'mcp-client' | 'framework';
  setupShape: string;
  description: string;
  configIntro: string;
  installHint: string;
};

export type CliEnvironment = {
  slug: string;
  label: string;
  titleSuffix: string;
  description: string;
  exampleIntro: string;
};

export type ComparisonPage = {
  slug: string;
  title: string;
  h1: string;
  description: string;
  competitor: string;
  positioning: string;
};

export type UseCasePage = {
  slug: string;
  title: string;
  h1: string;
  description: string;
  audience: string;
  primaryLink: string;
};

export const matrixClients: MatrixClient[] = [
  {
    slug: 'claude-code',
    label: 'Claude Code',
    shortLabel: 'Claude Code',
    category: 'mcp-client',
    setupShape: 'stdio MCP config',
    description: 'Connect local KosmoKrator integrations to Claude Code through one scoped MCP gateway entry.',
    configIntro: 'Add KosmoKrator as a stdio MCP server in the Claude Code project config and select the integrations that should be visible.',
    installHint: 'Claude Code can launch the local kosmo binary directly from the project MCP config.',
  },
  {
    slug: 'cursor',
    label: 'Cursor',
    shortLabel: 'Cursor',
    category: 'mcp-client',
    setupShape: '.cursor/mcp.json',
    description: 'Expose selected local integrations to Cursor through KosmoKrator without configuring each service as its own MCP server.',
    configIntro: 'Create or update .cursor/mcp.json with a KosmoKrator stdio server entry.',
    installHint: 'Use the same KosmoKrator install and integration credentials that power terminal and headless runs.',
  },
  {
    slug: 'codex',
    label: 'Codex',
    shortLabel: 'Codex',
    category: 'mcp-client',
    setupShape: 'MCP server config',
    description: 'Use KosmoKrator as a local MCP proxy for Codex so coding sessions can reach selected integrations with explicit write policy.',
    configIntro: 'Register kosmo mcp:serve as a local stdio server and choose the integration allowlist.',
    installHint: 'Keep write access denied or ask-based unless the workspace is trusted.',
  },
  {
    slug: 'openai-agents-sdk',
    label: 'OpenAI Agents SDK',
    shortLabel: 'OpenAI Agents',
    category: 'framework',
    setupShape: 'HostedMCPTool-compatible local server',
    description: 'Attach KosmoKrator integration tools to OpenAI Agents SDK workflows through a local MCP gateway.',
    configIntro: 'Start the KosmoKrator MCP gateway locally and point the OpenAI Agents SDK MCP tool at that process or wrapper.',
    installHint: 'Use headless JSON commands for CI-style execution and MCP for agent tool discovery.',
  },
  {
    slug: 'claude-agent-sdk',
    label: 'Claude Agent SDK',
    shortLabel: 'Claude Agent SDK',
    category: 'framework',
    setupShape: 'Claude SDK MCP server entry',
    description: 'Give Claude Agent SDK workflows access to KosmoKrator integrations through a local MCP server.',
    configIntro: 'Add a KosmoKrator stdio MCP server to the Claude Agent SDK options.',
    installHint: 'Use a narrow integration list so the agent does not load unrelated tools.',
  },
  {
    slug: 'vercel-ai-sdk',
    label: 'Vercel AI SDK',
    shortLabel: 'Vercel AI SDK',
    category: 'framework',
    setupShape: '@ai-sdk/mcp client',
    description: 'Use KosmoKrator as a local integration gateway for Vercel AI SDK agents and scripts.',
    configIntro: 'Create an MCP client that starts or connects to the KosmoKrator gateway for the selected integration.',
    installHint: 'Prefer CLI JSON calls when a workflow only needs one deterministic integration operation.',
  },
  {
    slug: 'langchain',
    label: 'LangChain',
    shortLabel: 'LangChain',
    category: 'framework',
    setupShape: 'MCP adapter or CLI tool wrapper',
    description: 'Bridge LangChain agents to local KosmoKrator integration tools through MCP or headless CLI calls.',
    configIntro: 'Use the MCP gateway when the agent should discover tools, or wrap kosmo integrations:call for fixed chains.',
    installHint: 'Keep the gateway scoped to the integration and operation class needed by the chain.',
  },
  {
    slug: 'langgraph',
    label: 'LangGraph',
    shortLabel: 'LangGraph',
    category: 'framework',
    setupShape: 'graph node via MCP or CLI',
    description: 'Run KosmoKrator integration calls from LangGraph nodes while preserving local credentials and permissions.',
    configIntro: 'Use a graph node that calls the KosmoKrator CLI for deterministic steps or an MCP client for dynamic tool selection.',
    installHint: 'Headless CLI calls fit repeatable graph edges; MCP fits exploratory agent nodes.',
  },
  {
    slug: 'crewai',
    label: 'CrewAI',
    shortLabel: 'CrewAI',
    category: 'framework',
    setupShape: 'tool wrapper around MCP or CLI',
    description: 'Expose KosmoKrator integrations to CrewAI workers as scoped local tools.',
    configIntro: 'Wrap kosmo integrations:call for specific tasks or connect workers to a local MCP gateway.',
    installHint: 'Use per-worker integration scopes to avoid giving every worker every tool.',
  },
  {
    slug: 'generic-mcp',
    label: 'Generic MCP Clients',
    shortLabel: 'MCP clients',
    category: 'mcp-client',
    setupShape: 'standard stdio MCP server',
    description: 'Connect any stdio-compatible MCP client to local KosmoKrator integration tools.',
    configIntro: 'Register kosmo mcp:serve as the command for a local stdio MCP server.',
    installHint: 'Start with read-only write policy and expand only for trusted projects.',
  },
];

export const cliEnvironments: CliEnvironment[] = [
  {
    slug: 'ci',
    label: 'CI',
    titleSuffix: 'CI',
    description: 'Run integration calls from CI jobs with JSON output, explicit credentials, and predictable exit status.',
    exampleIntro: 'Use this shape when a pipeline needs to read or update an external service.',
  },
  {
    slug: 'cron',
    label: 'cron jobs',
    titleSuffix: 'Cron Jobs',
    description: 'Schedule repeatable integration workflows from cron while keeping credentials in KosmoKrator config.',
    exampleIntro: 'Use the headless CLI from cron when an operation should run without an interactive agent session.',
  },
  {
    slug: 'shell-scripts',
    label: 'shell scripts',
    titleSuffix: 'Shell Scripts',
    description: 'Call integration functions from shell scripts with stable JSON input and output.',
    exampleIntro: 'Use shell scripts for small local automations that need one or more integration calls.',
  },
  {
    slug: 'headless-automation',
    label: 'headless automation',
    titleSuffix: 'Headless Automation',
    description: 'Use KosmoKrator as a non-interactive integration runtime for local automations and wrappers.',
    exampleIntro: 'Use headless automation when another tool needs a stable local command surface.',
  },
  {
    slug: 'coding-agents',
    label: 'coding agents',
    titleSuffix: 'Coding Agents',
    description: 'Let coding agents discover schemas and execute integration functions through CLI commands or MCP.',
    exampleIntro: 'Use this pattern when another coding agent needs exact commands and schema discovery.',
  },
];

export const comparisonPages: ComparisonPage[] = [
  {
    slug: 'composio',
    title: 'KosmoKrator vs Composio - Local MCP Gateway and CLI Integrations',
    h1: 'KosmoKrator vs Composio',
    description: 'Compare KosmoKrator with Composio for local MCP gateway, CLI integration runtime, agent permissions, and hosted agent auth workflows.',
    competitor: 'Composio',
    positioning: 'Composio is a hosted integration and auth platform. KosmoKrator is a local terminal coding agent and integration runtime that exposes selected tools through CLI, Lua, and MCP.',
  },
  {
    slug: 'claude-code',
    title: 'KosmoKrator vs Claude Code - Terminal Agent, MCP Gateway, and Integrations',
    h1: 'KosmoKrator vs Claude Code',
    description: 'Compare KosmoKrator with Claude Code for terminal coding, local MCP gateway usage, headless automation, ACP, integrations, and subagents.',
    competitor: 'Claude Code',
    positioning: 'Claude Code is a coding agent client. KosmoKrator can be used directly as a terminal agent or as a local MCP gateway that exposes selected integrations to clients like Claude Code.',
  },
  {
    slug: 'codex-cli',
    title: 'KosmoKrator vs Codex CLI - Local Agent Runtime and MCP Integrations',
    h1: 'KosmoKrator vs Codex CLI',
    description: 'Compare KosmoKrator with Codex CLI for provider flexibility, local integration commands, MCP gateway usage, and headless automation.',
    competitor: 'Codex CLI',
    positioning: 'Codex CLI focuses on coding sessions. KosmoKrator combines coding sessions with a local integration catalog, MCP gateway, Lua workflows, and PHP SDK surfaces.',
  },
  {
    slug: 'opencode',
    title: 'KosmoKrator vs OpenCode - Terminal AI Coding Agent Comparison',
    h1: 'KosmoKrator vs OpenCode',
    description: 'Compare KosmoKrator with OpenCode for terminal coding, permissions, subagents, local integrations, MCP, ACP, and headless automation.',
    competitor: 'OpenCode',
    positioning: 'OpenCode and KosmoKrator both target terminal AI coding. KosmoKrator leans into local integration execution, MCP gateway export, ACP, Lua, and PHP embedding.',
  },
  {
    slug: 'hosted-mcp-platforms',
    title: 'Local MCP Gateway vs Hosted MCP Platform - KosmoKrator',
    h1: 'Local MCP Gateway vs Hosted MCP Platform',
    description: 'Compare local MCP gateway workflows with hosted MCP platforms for credentials, permissions, latency, tool scope, and coding-agent automation.',
    competitor: 'Hosted MCP platforms',
    positioning: 'Hosted platforms centralize integration auth and operations. A local KosmoKrator gateway keeps credentials, policy, and execution close to the developer workspace.',
  },
];

export const useCasePages: UseCasePage[] = [
  {
    slug: 'local-mcp-gateway',
    title: 'Local MCP Gateway for AI Agents - KosmoKrator',
    h1: 'Local MCP Gateway for AI Agents',
    description: 'Use KosmoKrator as a local MCP gateway for Claude Code, Cursor, Codex, and other AI agents with scoped integrations and write policy.',
    audience: 'developers who want agent integrations without handing every client every credential',
    primaryLink: '/docs/mcp',
  },
  {
    slug: 'headless-ai-coding-agent',
    title: 'Headless AI Coding Agent for Scripts and CI - KosmoKrator',
    h1: 'Headless AI Coding Agent',
    description: 'Run KosmoKrator non-interactively from scripts, CI, wrappers, and other tools with JSON and stream-json output.',
    audience: 'teams automating coding-agent runs outside an interactive terminal',
    primaryLink: '/docs/headless',
  },
  {
    slug: 'terminal-ai-coding-agent',
    title: 'Terminal AI Coding Agent with TUI, ANSI, and Permissions - KosmoKrator',
    h1: 'Terminal AI Coding Agent',
    description: 'Run a terminal-first AI coding agent with persistent sessions, permissions, provider switching, subagents, MCP, and integrations.',
    audience: 'developers who want a local shell-first coding agent',
    primaryLink: '/docs/getting-started',
  },
  {
    slug: 'ai-coding-agent-subagents',
    title: 'AI Coding Agent with Subagents and Swarm Workflows - KosmoKrator',
    h1: 'AI Coding Agent with Subagents',
    description: 'Use KosmoKrator subagents for parallel research, implementation, audits, and long-running coding workflows.',
    audience: 'developers running larger audits, fixes, and multi-step coding tasks',
    primaryLink: '/docs/agents',
  },
  {
    slug: 'php-ai-agent-sdk',
    title: 'PHP AI Agent SDK for Headless Coding Workflows - KosmoKrator',
    h1: 'PHP AI Agent SDK',
    description: 'Embed KosmoKrator headless runs, MCP calls, integrations, and Lua workflows in PHP applications.',
    audience: 'PHP developers embedding an agent runtime instead of shelling out ad hoc',
    primaryLink: '/docs/sdk',
  },
  {
    slug: 'ai-agent-cli-for-ci',
    title: 'AI Agent CLI for CI and Automation - KosmoKrator',
    h1: 'AI Agent CLI for CI',
    description: 'Use KosmoKrator as a command-line AI agent in CI pipelines with startup checks, JSON output, permissions, and provider configuration.',
    audience: 'teams adding coding-agent automation to pipelines',
    primaryLink: '/docs/cli-reference',
  },
  {
    slug: 'telegram-ai-coding-agent',
    title: 'Telegram AI Coding Agent Gateway - KosmoKrator',
    h1: 'Telegram AI Coding Agent Gateway',
    description: 'Run and route KosmoKrator coding sessions through Telegram with access controls and runtime configuration.',
    audience: 'operators who want remote access to local agent workflows',
    primaryLink: '/docs/gateway-telegram',
  },
  {
    slug: 'local-integration-runtime',
    title: 'Local Integration Runtime for AI Agents - KosmoKrator',
    h1: 'Local Integration Runtime for AI Agents',
    description: 'Call business tools from AI agents through local CLI, Lua, and MCP gateway surfaces backed by the OpenCompany integration catalog.',
    audience: 'developers connecting agents to business tools while keeping execution local',
    primaryLink: '/integrations',
  },
];

export function slugify(value: string): string {
  return value.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '') || 'other';
}

export function siteUrl(path: string): string {
  return `${SITE_URL}${path === '/' ? '' : path}`;
}

export function ogImageSlug(parts: Array<string | undefined>): string {
  return parts.filter((part): part is string => Boolean(part)).map(slugify).join('-') || 'default';
}

export function ogImageUrl(parts: Array<string | undefined>): string {
  return siteUrl(`/og/${ogImageSlug(parts)}.svg`);
}

export function jsonLd(value: unknown): string {
  return JSON.stringify(value).replace(/</g, '\\u003c');
}

export function integrationCliTitle(integration: Integration): string {
  return `${integration.name} CLI for AI Agents`;
}

export function integrationMcpTitle(integration: Integration): string {
  return `${integration.name} MCP Gateway for AI Agents`;
}

export function integrationOverviewTitle(integration: Integration): string {
  return `${integration.name} MCP, CLI, and Lua Integration for AI Agents`;
}

export function integrationFrameworkTitle(integration: Integration, client: MatrixClient): string {
  return `${integration.name} MCP Integration for ${client.label}`;
}

export function integrationEnvironmentTitle(integration: Integration, environment: CliEnvironment): string {
  return `${integration.name} CLI for ${environment.titleSuffix}`;
}

export function shouldIndexIntegration(integration: Integration): boolean {
  return integration.tools.length > 0 && integration.description.trim().length >= 40;
}

export function integrationKeywords(integration: Integration, extras: string[] = []): string {
  const terms = [
    `${integration.name} CLI`,
    `${integration.name} MCP`,
    `${integration.name} AI agent integration`,
    `${integration.name} coding agent`,
    `${integration.name} headless automation`,
    'KosmoKrator',
    'local MCP gateway',
    'AI coding agent',
    ...integration.seo.keywords,
    ...extras,
  ];

  return [...new Set(terms.filter(Boolean))].join(', ');
}

export function breadcrumbJsonLd(items: Array<{ name: string; url: string }>): Record<string, unknown> {
  return {
    '@context': 'https://schema.org',
    '@type': 'BreadcrumbList',
    itemListElement: items.map((item, index) => ({
      '@type': 'ListItem',
      position: index + 1,
      name: item.name,
      item: item.url,
    })),
  };
}

export function softwareJsonLd(): Record<string, unknown> {
  return {
    '@context': 'https://schema.org',
    '@type': 'SoftwareApplication',
    name: 'KosmoKrator',
    applicationCategory: 'DeveloperApplication',
    operatingSystem: 'macOS, Linux, Android Termux',
    description: 'Terminal AI coding agent with headless automation, local MCP gateway, integrations, ACP, PHP SDK, Lua, and subagents.',
    url: SITE_URL,
    codeRepository: 'https://github.com/OpenCompanyApp/kosmokrator',
  };
}

export function faqJsonLd(faqs: Array<{ question: string; answer: string }>): Record<string, unknown> {
  return {
    '@context': 'https://schema.org',
    '@type': 'FAQPage',
    mainEntity: faqs.map((faq) => ({
      '@type': 'Question',
      name: faq.question,
      acceptedAnswer: {
        '@type': 'Answer',
        text: faq.answer,
      },
    })),
  };
}

export function articleJsonLd(title: string, description: string, path: string): Record<string, unknown> {
  return {
    '@context': 'https://schema.org',
    '@type': 'TechArticle',
    headline: title,
    description,
    url: siteUrl(path),
    author: {
      '@type': 'Organization',
      name: 'OpenCompany',
    },
    publisher: {
      '@type': 'Organization',
      name: 'OpenCompany',
    },
  };
}
