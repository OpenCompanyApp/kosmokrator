import { defineConfig } from 'astro/config';
import starlight from '@astrojs/starlight';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
  site: 'https://kosmokrator.dev',
  trailingSlash: 'never',
  build: {
    concurrency: 1,
  },
  vite: {
    plugins: [tailwindcss()],
  },
  integrations: [
    starlight({
      title: 'KosmoKrator',
      description: 'Terminal AI coding agent with headless automation, ACP, SDK, Lua, integrations, MCP, and subagents.',
      favicon: '/favicon.svg',
      logo: {
        src: './src/assets/logo.svg',
        replacesTitle: false,
      },
      customCss: ['./src/styles/custom.css'],
      social: [
        { icon: 'github', label: 'GitHub', href: 'https://github.com/OpenCompanyApp/kosmokrator' },
      ],
      lastUpdated: true,
      tableOfContents: {
        minHeadingLevel: 2,
        maxHeadingLevel: 4,
      },
      head: [
        {
          tag: 'script',
          attrs: { 'is:inline': true },
          content:
            "try{if(!localStorage.getItem('starlight-theme'))localStorage.setItem('starlight-theme','dark')}catch(e){}",
        },
        {
          tag: 'meta',
          attrs: {
            name: 'keywords',
            content: 'AI coding agent, terminal coding agent, headless agent, ACP, MCP, Lua integrations, PHP SDK, CLI',
          },
        },
        {
          tag: 'meta',
          attrs: {
            property: 'og:image',
            content: 'https://kosmokrator.dev/og/default.svg',
          },
        },
        {
          tag: 'meta',
          attrs: {
            property: 'og:image:width',
            content: '1200',
          },
        },
        {
          tag: 'meta',
          attrs: {
            property: 'og:image:height',
            content: '630',
          },
        },
        {
          tag: 'meta',
          attrs: {
            name: 'twitter:card',
            content: 'summary_large_image',
          },
        },
        {
          tag: 'meta',
          attrs: {
            name: 'twitter:image',
            content: 'https://kosmokrator.dev/og/default.svg',
          },
        },
        {
          tag: 'script',
          attrs: {
            async: true,
            src: 'https://plausible.gingermedia.biz/js/pa-8vpUDam-s3dygrgBnXxTP.js',
          },
        },
        {
          tag: 'script',
          attrs: { 'is:inline': true },
          content:
            'window.plausible=window.plausible||function(){(plausible.q=plausible.q||[]).push(arguments)},plausible.init=plausible.init||function(i){plausible.o=i||{}};plausible.init()',
        },
      ],
      sidebar: [
        {
          label: 'Start',
          items: [
            { slug: 'docs/getting-started' },
            { slug: 'docs/installation' },
            { slug: 'docs/termux' },
            { slug: 'docs/configuration' },
            { slug: 'docs/settings-reference' },
            { slug: 'docs/troubleshooting' },
            { slug: 'docs/changelog' },
          ],
        },
        {
          label: 'Run KosmoKrator',
          items: [
            { slug: 'docs/headless' },
            { slug: 'docs/cli-reference' },
            { slug: 'docs/providers' },
            { slug: 'docs/permissions' },
            { slug: 'docs/commands' },
            { slug: 'docs/ui-guide' },
          ],
        },
        {
          label: 'Automation Surfaces',
          items: [
            { slug: 'docs/sdk' },
            { slug: 'docs/acp' },
            { slug: 'docs/integrations' },
            { slug: 'docs/mcp' },
            { slug: 'docs/lua' },
            { slug: 'docs/web' },
            { slug: 'docs/gateway-telegram' },
          ],
        },
        {
          label: 'Agent Runtime',
          items: [
            { slug: 'docs/tools' },
            { slug: 'docs/agents' },
            { slug: 'docs/context' },
            { slug: 'docs/sessions-memory' },
            { slug: 'docs/skills' },
            { slug: 'docs/patterns' },
            { slug: 'docs/architecture' },
          ],
        },
      ],
    }),
  ],
});
