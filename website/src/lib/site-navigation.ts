import { getIntegrations, integrationUrl, type Integration } from './integrations-catalog';
import {
  cliEnvironments,
  comparisonPages,
  matrixClients,
  useCasePages,
} from './seo';

export type FooterLink = {
  label: string;
  href: string;
};

export type FooterSection = {
  title: string;
  links: FooterLink[];
};

export type SiteMapSection = FooterSection & {
  intro?: string;
};

const docPages: FooterLink[] = [
  { label: 'Docs Home', href: '/docs' },
  { label: 'Getting Started', href: '/docs/getting-started' },
  { label: 'Installation', href: '/docs/installation' },
  { label: 'Termux', href: '/docs/termux' },
  { label: 'Configuration', href: '/docs/configuration' },
  { label: 'Settings Reference', href: '/docs/settings-reference' },
  { label: 'Troubleshooting', href: '/docs/troubleshooting' },
  { label: 'Changelog', href: '/docs/changelog' },
  { label: 'Headless', href: '/docs/headless' },
  { label: 'CLI Reference', href: '/docs/cli-reference' },
  { label: 'Providers', href: '/docs/providers' },
  { label: 'Permissions', href: '/docs/permissions' },
  { label: 'Commands', href: '/docs/commands' },
  { label: 'UI Guide', href: '/docs/ui-guide' },
  { label: 'SDK', href: '/docs/sdk' },
  { label: 'ACP', href: '/docs/acp' },
  { label: 'Integrations CLI', href: '/docs/integrations' },
  { label: 'MCP', href: '/docs/mcp' },
  { label: 'Lua', href: '/docs/lua' },
  { label: 'Web Search and Fetch', href: '/docs/web' },
  { label: 'Telegram Gateway', href: '/docs/gateway-telegram' },
  { label: 'Tools', href: '/docs/tools' },
  { label: 'Agents', href: '/docs/agents' },
  { label: 'Context', href: '/docs/context' },
  { label: 'Sessions and Memory', href: '/docs/sessions-memory' },
  { label: 'Skills', href: '/docs/skills' },
  { label: 'Patterns', href: '/docs/patterns' },
  { label: 'Architecture', href: '/docs/architecture' },
];

const corePages: FooterLink[] = [
  { label: 'Home', href: '/' },
  { label: 'Documentation', href: '/docs' },
  { label: 'Install', href: '/docs/installation' },
  { label: 'Integration Catalog', href: '/integrations' },
  { label: 'Local MCP Gateway', href: '/use-cases/local-mcp-gateway' },
  { label: 'Website Map', href: '/site-map' },
  { label: 'GitHub', href: 'https://github.com/OpenCompanyApp/kosmokrator' },
];

const runtimePages: FooterLink[] = [
  { label: 'Terminal Agent', href: '/use-cases/terminal-ai-coding-agent' },
  { label: 'Headless Agent', href: '/use-cases/headless-ai-coding-agent' },
  { label: 'Subagent Swarms', href: '/use-cases/ai-coding-agent-subagents' },
  { label: 'PHP SDK', href: '/use-cases/php-ai-agent-sdk' },
  { label: 'CI Agent CLI', href: '/use-cases/ai-agent-cli-for-ci' },
  { label: 'Telegram Agent', href: '/use-cases/telegram-ai-coding-agent' },
  { label: 'Local Integrations', href: '/use-cases/local-integration-runtime' },
  { label: 'ACP Server', href: '/docs/acp' },
];

function integrationPages(integration: Integration): FooterLink[] {
  const base = integrationUrl(integration);

  return [
    { label: `${integration.name} overview`, href: base },
    { label: `${integration.name} CLI`, href: `${base}/cli` },
    { label: `${integration.name} MCP`, href: `${base}/mcp` },
    { label: `${integration.name} Lua`, href: `${base}/lua` },
    { label: `${integration.name} CLI shortcut`, href: `/cli/${integration.route_slug}` },
    ...matrixClients.map((client) => ({
      label: `${integration.name} for ${client.label}`,
      href: `${base}/framework/${client.slug}`,
    })),
    ...cliEnvironments.map((environment) => ({
      label: `${integration.name} CLI for ${environment.titleSuffix}`,
      href: `${base}/cli/${environment.slug}`,
    })),
  ];
}

export function getIntegrationCategories(): string[] {
  return [...new Set(getIntegrations().map((integration) => integration.category))].sort((a, b) => a.localeCompare(b));
}

export function getMegaFooterSections(): FooterSection[] {
  const integrations = getIntegrations();
  const prioritySlugs = new Set([
    'clickup',
    'github',
    'gitlab',
    'jira',
    'linear',
    'slack',
    'notion',
    'google-drive',
    'google-sheets',
    'gmail',
    'hubspot',
    'salesforce',
    'stripe',
    'shopify',
    'airtable',
    'zendesk',
  ]);
  const featuredCategoryNames = new Set([
    'automation',
    'crm',
    'developer-tools',
    'finance',
    'marketing',
    'messaging',
    'payments',
    'productivity',
    'sales',
    'support',
  ]);
  const priorityIntegrations = integrations
    .filter((integration) => prioritySlugs.has(integration.route_slug))
    .sort((a, b) => a.name.localeCompare(b.name));
  const categories = getIntegrationCategories()
    .filter((category) => featuredCategoryNames.has(category.toLowerCase().replace(/[^a-z0-9]+/g, '-')))
    .slice(0, 10);

  return [
    { title: 'KosmoKrator', links: corePages.slice(0, 6) },
    { title: 'Run Modes', links: runtimePages },
    {
      title: 'Docs',
      links: [
        ...docPages.slice(1, 9),
        { label: 'All Docs', href: '/docs' },
        { label: 'Changelog', href: '/docs/changelog' },
      ],
    },
    {
      title: 'MCP Clients',
      links: matrixClients.map((client) => ({
        label: client.label,
        href: `/mcp/${client.slug}`,
      })),
    },
    {
      title: 'Integrations',
      links: [
        { label: 'Integration Catalog', href: '/integrations' },
        { label: 'All Categories', href: '/site-map#integration-categories' },
        { label: 'Integration Matrix', href: '/site-map#integration-matrix' },
        { label: 'CLI Shortcuts', href: '/site-map#cli-shortcuts' },
        { label: 'Local Integration Runtime', href: '/use-cases/local-integration-runtime' },
      ],
    },
    {
      title: 'Categories',
      links: [
        ...categories.map((category) => ({
          label: category,
          href: `/integrations/categories/${category.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '')}`,
        })),
        { label: 'All Categories', href: '/site-map#integration-categories' },
      ],
    },
    {
      title: 'Popular CLI',
      links: priorityIntegrations.slice(0, 12).map((integration) => ({
        label: `${integration.name} CLI`,
        href: `/cli/${integration.route_slug}`,
      })),
    },
    {
      title: 'Compare',
      links: comparisonPages.map((page) => ({
        label: page.competitor,
        href: `/compare/${page.slug}`,
      })),
    },
    {
      title: 'Site Index',
      links: [
        { label: 'Complete Website Map', href: '/site-map' },
        { label: 'All Use Cases', href: '/site-map#use-cases' },
        { label: 'All MCP Client Pages', href: '/site-map#mcp-clients' },
        { label: 'All Comparisons', href: '/site-map#comparisons' },
        { label: 'All Generated Pages', href: '/site-map#integration-matrix' },
        { label: 'GitHub', href: 'https://github.com/OpenCompanyApp/kosmokrator' },
      ],
    },
  ];
}

export function getSiteMapSections(): SiteMapSection[] {
  const integrations = getIntegrations();

  return [
    {
      title: 'Core Pages',
      intro: 'Primary product, documentation, and navigation pages.',
      links: corePages,
    },
    {
      title: 'Docs',
      intro: 'Every Starlight documentation page.',
      links: docPages,
    },
    {
      title: 'Use Cases',
      intro: 'Problem-oriented pages for search, evaluation, and onboarding.',
      links: useCasePages.map((page) => ({
        label: page.h1,
        href: `/use-cases/${page.slug}`,
      })),
    },
    {
      title: 'MCP Clients',
      intro: 'Client and framework pages for the local MCP gateway.',
      links: matrixClients.map((client) => ({
        label: `KosmoKrator MCP Gateway for ${client.label}`,
        href: `/mcp/${client.slug}`,
      })),
    },
    {
      title: 'Comparisons',
      intro: 'Competitive and positioning pages.',
      links: comparisonPages.map((page) => ({
        label: page.h1,
        href: `/compare/${page.slug}`,
      })),
    },
    {
      title: 'Integration Categories',
      intro: 'Category hub pages that lead into integration-specific docs.',
      links: getIntegrationCategories().map((category) => ({
        label: `${category} integrations`,
        href: `/integrations/categories/${category.toLowerCase().replace(/[^a-z0-9]+/g, '-').replace(/^-+|-+$/g, '')}`,
      })),
    },
    {
      title: 'CLI Shortcuts',
      intro: 'Exact-match command-line pages such as ClickUp CLI and Slack CLI.',
      links: integrations.map((integration) => ({
        label: `${integration.name} CLI`,
        href: `/cli/${integration.route_slug}`,
      })),
    },
    {
      title: 'Integration Matrix',
      intro: 'Every generated integration overview, CLI, MCP, Lua, framework, and automation page.',
      links: integrations.flatMap(integrationPages),
    },
  ];
}
