import { defineConfig } from 'vitepress'

export default defineConfig({
  title: 'cakephp-audit-stash',
  description: 'Audit trail plugin for CakePHP — entity logging, admin viewer, monitoring, retention, and tamper-evident hash chain.',
  base: '/cakephp-audit-stash/',
  cleanUrls: true,
  head: [
    ['link', { rel: 'icon', href: '/cakephp-audit-stash/favicon.svg', type: 'image/svg+xml' }],
  ],
  themeConfig: {
    logo: '/logo.svg',
    nav: [
      { text: 'Guide', link: '/guide/', activeMatch: '/guide/' },
      { text: 'Features', link: '/features/', activeMatch: '/features/' },
      {
        text: 'Links',
        items: [
          { text: 'Live Demo', link: 'https://sandbox.dereuromark.de/sandbox/audit-stash' },
          { text: 'GitHub', link: 'https://github.com/dereuromark/cakephp-audit-stash' },
          { text: 'Packagist', link: 'https://packagist.org/packages/dereuromark/cakephp-audit-stash' },
          { text: 'Issues', link: 'https://github.com/dereuromark/cakephp-audit-stash/issues' },
        ],
      },
    ],
    sidebar: {
      '/guide/': [
        {
          text: 'Guide',
          items: [
            { text: 'Getting Started', link: '/guide/' },
            { text: 'Configuration', link: '/guide/configuration' },
            { text: 'Usage', link: '/guide/usage' },
            { text: 'View Helper', link: '/guide/view-helper' },
            { text: 'Testing', link: '/guide/testing' },
          ],
        },
      ],
      '/features/': [
        {
          text: 'Features',
          items: [
            { text: 'Overview', link: '/features/' },
            { text: 'Admin Viewer', link: '/features/viewer' },
            { text: 'Revert & Restore', link: '/features/revert' },
            { text: 'Monitoring & Alerting', link: '/features/monitoring' },
            { text: 'Retention & Cleanup', link: '/features/retention' },
            { text: 'Tamper-Evidence', link: '/features/tamper-evidence' },
            { text: 'GDPR', link: '/features/gdpr' },
          ],
        },
      ],
    },
    socialLinks: [
      { icon: 'github', link: 'https://github.com/dereuromark/cakephp-audit-stash' },
    ],
    search: {
      provider: 'local',
    },
    editLink: {
      pattern: 'https://github.com/dereuromark/cakephp-audit-stash/edit/master/docs/:path',
      text: 'Edit this page on GitHub',
    },
    footer: {
      message: 'Released under the MIT License.',
      copyright: 'Copyright Mark Scherer',
    },
  },
})
