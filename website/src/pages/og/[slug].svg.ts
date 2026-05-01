import type { APIRoute } from 'astro';
import { getIntegrations } from '../../lib/integrations-catalog';
import {
  cliEnvironments,
  comparisonPages,
  integrationCliTitle,
  integrationEnvironmentTitle,
  integrationFrameworkTitle,
  integrationMcpTitle,
  integrationOverviewTitle,
  matrixClients,
  ogImageSlug,
  useCasePages,
} from '../../lib/seo';

type OgImage = {
  slug: string;
  title: string;
  subtitle: string;
  kicker: string;
};

function stripTags(value: string): string {
  return value.replace(/<[^>]+>/g, '').replace(/\s+/g, ' ').trim();
}

function escapeXml(value: string): string {
  return stripTags(value)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

function wrapText(value: string, maxLength: number, maxLines: number): string[] {
  const words = stripTags(value).split(/\s+/).filter(Boolean);
  const lines: string[] = [];
  let current = '';

  for (const word of words) {
    const next = current === '' ? word : `${current} ${word}`;
    if (next.length > maxLength && current !== '') {
      lines.push(current);
      current = word;
      if (lines.length === maxLines - 1) {
        break;
      }
    } else {
      current = next;
    }
  }

  const consumed = lines.join(' ').split(/\s+/).filter(Boolean).length + current.split(/\s+/).filter(Boolean).length;
  if (current !== '') {
    const remaining = words.length > consumed;
    lines.push(remaining ? `${current.replace(/[.,;:]$/, '')}...` : current);
  }

  return lines.slice(0, maxLines);
}

function renderOgImage(image: OgImage): string {
  const titleLines = wrapText(image.title, 30, 3);
  const subtitleLines = wrapText(image.subtitle, 64, 2);
  const titleStart = titleLines.length === 1 ? 268 : titleLines.length === 2 ? 232 : 204;

  return `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 1200 630" width="1200" height="630" role="img" aria-label="${escapeXml(image.title)}">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
      <stop offset="0" stop-color="#07070d"/>
      <stop offset="0.58" stop-color="#11111b"/>
      <stop offset="1" stop-color="#1a0710"/>
    </linearGradient>
    <linearGradient id="accent" x1="0" y1="0" x2="1" y2="0">
      <stop offset="0" stop-color="#dc143c"/>
      <stop offset="1" stop-color="#facc15"/>
    </linearGradient>
    <pattern id="grid" width="40" height="40" patternUnits="userSpaceOnUse">
      <path d="M 40 0 L 0 0 0 40" fill="none" stroke="#ffffff" stroke-opacity=".045" stroke-width="1"/>
    </pattern>
  </defs>
  <rect width="1200" height="630" fill="url(#bg)"/>
  <rect width="1200" height="630" fill="url(#grid)"/>
  <path d="M72 112h1056" stroke="url(#accent)" stroke-width="4" stroke-linecap="round"/>
  <rect x="72" y="92" width="1056" height="466" rx="22" fill="#0c0c14" fill-opacity=".88" stroke="#ffffff" stroke-opacity=".14"/>
  <rect x="96" y="118" width="196" height="42" rx="21" fill="#dc143c" fill-opacity=".18" stroke="#dc143c" stroke-opacity=".72"/>
  <text x="118" y="146" fill="#f8fafc" font-family="Inter, ui-sans-serif, system-ui, sans-serif" font-size="18" font-weight="800" letter-spacing="2.5">${escapeXml(image.kicker.toUpperCase())}</text>
  <text x="96" y="${titleStart}" fill="#f8fafc" font-family="Inter, ui-sans-serif, system-ui, sans-serif" font-size="70" font-weight="900" letter-spacing="0">
    ${titleLines.map((line, index) => `<tspan x="96" dy="${index === 0 ? 0 : 78}">${escapeXml(line)}</tspan>`).join('')}
  </text>
  <text x="98" y="452" fill="#cbd5e1" font-family="Inter, ui-sans-serif, system-ui, sans-serif" font-size="29" font-weight="500">
    ${subtitleLines.map((line, index) => `<tspan x="98" dy="${index === 0 ? 0 : 40}">${escapeXml(line)}</tspan>`).join('')}
  </text>
  <rect x="96" y="510" width="530" height="1" fill="#ffffff" fill-opacity=".16"/>
  <text x="96" y="544" fill="#94a3b8" font-family="ui-monospace, SFMono-Regular, Menlo, monospace" font-size="24">$ kosmo</text>
  <text x="964" y="544" fill="#f8fafc" font-family="Inter, ui-sans-serif, system-ui, sans-serif" font-size="28" font-weight="900">KosmoKrator</text>
  <rect x="1042" y="124" width="54" height="54" rx="14" fill="#dc143c"/>
  <text x="1069" y="162" text-anchor="middle" fill="#ffffff" font-family="Inter, ui-sans-serif, system-ui, sans-serif" font-size="33" font-weight="900">K</text>
</svg>`;
}

function ogImages(): OgImage[] {
  const images = new Map<string, OgImage>();
  const add = (parts: Array<string | undefined>, title: string, subtitle: string, kicker = 'KosmoKrator') => {
    const slug = ogImageSlug(parts);
    images.set(slug, { slug, title, subtitle, kicker });
  };

  const integrations = getIntegrations();
  const categories = [...new Set(integrations.map((integration) => integration.category))].sort();

  add(['default'], 'KosmoKrator', 'Terminal AI coding agent with headless CLI, local MCP gateway, Lua, integrations, ACP, SDK, and subagents.', 'AI coding agent');
  add(['home'], 'KosmoKrator', 'Terminal AI coding agent with headless CLI, local MCP gateway, Lua, integrations, ACP, SDK, and subagents.', 'AI coding agent');
  add(['integrations'], 'AI Agent Integration Catalog', 'Generated CLI, MCP gateway, Lua, credential, schema, and framework setup pages for AI agents.', 'Integrations');

  for (const category of categories) {
    add(['category', category], `${category} CLI and MCP Integrations`, `Browse ${category} integrations for AI agents with KosmoKrator CLI, MCP gateway, and Lua docs.`, 'Category');
  }

  for (const integration of integrations) {
    const count = `${integration.tools.length} functions`;
    add(['integration', integration.route_slug], integrationOverviewTitle(integration), `${count}, ${integration.auth_strategy_label} auth, CLI commands, MCP gateway setup, Lua API, and schema docs.`, 'Integration');
    add(['integration', integration.route_slug, 'cli'], integrationCliTitle(integration), `Headless ${integration.name} commands for scripts, CI, coding agents, JSON output, and schema discovery.`, 'CLI');
    add(['cli', integration.route_slug], integrationCliTitle(integration), `Exact-match ${integration.name} CLI shortcut for headless automation and coding-agent workflows.`, 'CLI');
    add(['integration', integration.route_slug, 'mcp'], integrationMcpTitle(integration), `Expose ${integration.name} tools to Claude Code, Cursor, Codex, and other MCP clients through a local gateway.`, 'MCP');
    add(['integration', integration.route_slug, 'lua'], `${integration.name} Lua API for KosmoKrator Agents`, `Agent-facing Lua namespace and function reference for ${integration.name}.`, 'Lua');

    for (const client of matrixClients) {
      add(['integration', integration.route_slug, 'framework', client.slug], integrationFrameworkTitle(integration, client), `Connect ${integration.name} to ${client.label} through KosmoKrator's local MCP gateway.`, 'Framework');
    }

    for (const environment of cliEnvironments) {
      add(['integration', integration.route_slug, 'cli', environment.slug], integrationEnvironmentTitle(integration, environment), `Use the ${integration.name} CLI for ${environment.label} with headless JSON commands and local credentials.`, 'Automation');
    }
  }

  for (const client of matrixClients) {
    add(['mcp', client.slug], `KosmoKrator MCP Gateway for ${client.label}`, `Use one local MCP gateway to expose selected integrations to ${client.shortLabel} with scoped tools and write policy.`, 'MCP Gateway');
  }

  for (const page of comparisonPages) {
    add(['compare', page.slug], page.h1, page.description, 'Comparison');
  }

  for (const page of useCasePages) {
    add(['use-case', page.slug], page.h1, page.description, 'Use Case');
  }

  return [...images.values()];
}

export function getStaticPaths() {
  return ogImages().map((image) => ({
    params: { slug: image.slug },
    props: { image },
  }));
}

export const GET: APIRoute = ({ props }) => {
  const { image } = props as { image: OgImage };

  return new Response(renderOgImage(image), {
    headers: {
      'Content-Type': 'image/svg+xml; charset=utf-8',
      'Cache-Control': 'public, max-age=31536000, immutable',
    },
  });
};
