export const PLAUSIBLE_SCRIPT_SRC = 'https://plausible.gingermedia.biz/js/pa-8vpUDam-s3dygrgBnXxTP.js';
export const POSTHOG_PROJECT_TOKEN = 'phc_m3ZCcdoaySRjVLd4MwTvPFTSs5AD5fedJBEqNtHd9kyf';
export const POSTHOG_API_HOST = 'https://g.gingermedia.biz';
export const POSTHOG_UI_HOST = 'https://eu.posthog.com';

export const plausibleSnippet = `
(function () {
  var host = window.location.hostname;
  var local = host === 'localhost' || host === '127.0.0.1' || host === '::1';
  window.plausible = window.plausible || function () {
    (window.plausible.q = window.plausible.q || []).push(arguments);
  };
  window.plausible.init = window.plausible.init || function (options) {
    window.plausible.o = options || {};
  };
  if (local || window.__kosmo_plausible_loaded) return;
  window.__kosmo_plausible_loaded = true;
  var script = document.createElement('script');
  script.async = true;
  script.src = '${PLAUSIBLE_SCRIPT_SRC}';
  document.head.appendChild(script);
  window.plausible.init();
})();
`.trim();

export const posthogSnippet = `
(function () {
  var host = window.location.hostname;
  var local = host === 'localhost' || host === '127.0.0.1' || host === '::1';
  if (local || window.__kosmo_posthog_initialized) return;
  window.__kosmo_posthog_initialized = true;
  !function(t,e){var o,n,p,r;e.__SV||(window.posthog && window.posthog.__loaded)||(window.posthog=e,e._i=[],e.init=function(i,s,a){function g(t,e){var o=e.split(".");2==o.length&&(t=t[o[0]],e=o[1]),t[e]=function(){t.push([e].concat(Array.prototype.slice.call(arguments,0)))}}(p=t.createElement("script")).type="text/javascript",p.crossOrigin="anonymous",p.async=!0,p.src=s.api_host.replace(".i.posthog.com","-assets.i.posthog.com")+"/static/array.js",(r=t.getElementsByTagName("script")[0]).parentNode.insertBefore(p,r);var u=e;for(void 0!==a?u=e[a]=[]:a="posthog",u.people=u.people||[],u.toString=function(t){var e="posthog";return"posthog"!==a&&(e+="."+a),t||(e+=" (stub)"),e},u.people.toString=function(){return u.toString(1)+".people (stub)"},o="Mi Ri init Vi Gi Rr Wi Ji Bi capture calculateEventProperties tn register register_once register_for_session unregister unregister_for_session an getFeatureFlag getFeatureFlagPayload getFeatureFlagResult isFeatureEnabled reloadFeatureFlags updateFlags updateEarlyAccessFeatureEnrollment getEarlyAccessFeatures on onFeatureFlags onSurveysLoaded onSessionId getSurveys getActiveMatchingSurveys renderSurvey displaySurvey cancelPendingSurvey canRenderSurvey canRenderSurveyAsync un identify setPersonProperties group resetGroups setPersonPropertiesForFlags resetPersonPropertiesForFlags setGroupPropertiesForFlags resetGroupPropertiesForFlags reset setIdentity clearIdentity get_distinct_id getGroups get_session_id get_session_replay_url alias set_config startSessionRecording stopSessionRecording sessionRecordingStarted captureException addExceptionStep captureLog startExceptionAutocapture stopExceptionAutocapture loadToolbar get_property getSessionProperty nn Xi createPersonProfile setInternalOrTestUser sn Hi cn opt_in_capturing opt_out_capturing has_opted_in_capturing has_opted_out_capturing get_explicit_consent_status is_capturing clear_opt_in_out_capturing Ki debug Lr rn getPageViewId captureTraceFeedback captureTraceMetric Di".split(" "),n=0;n<o.length;n++)g(u,o[n]);e._i.push([i,s,a])},e.__SV=1)}(document,window.posthog||[]);
  window.posthog.init('${POSTHOG_PROJECT_TOKEN}', {
    api_host: '${POSTHOG_API_HOST}',
    ui_host: '${POSTHOG_UI_HOST}',
    defaults: '2026-01-30',
    person_profiles: 'identified_only'
  });
})();
`.trim();

export const analyticsEventsSnippet = `
(function () {
  if (window.__kosmo_analytics_events_bound) return;
  window.__kosmo_analytics_events_bound = true;

  function isLocalHost() {
    var host = window.location.hostname;
    return host === 'localhost' || host === '127.0.0.1' || host === '::1';
  }

  function safeValue(value) {
    if (value === undefined || value === null || value === '') return undefined;
    if (typeof value === 'boolean' || typeof value === 'number') return value;
    var text = String(value);
    return text.length > 180 ? text.slice(0, 177) + '...' : text;
  }

  function cleanProperties(input) {
    var output = {};
    Object.keys(input || {}).forEach(function (key) {
      if (/code|token|secret|api[_-]?key|password|payload|command_text/i.test(key)) return;
      var value = safeValue(input[key]);
      if (value !== undefined) output[key] = value;
    });
    return output;
  }

  function parseJson(value) {
    if (!value) return {};
    try {
      var parsed = JSON.parse(value);
      return parsed && typeof parsed === 'object' ? parsed : {};
    } catch (error) {
      return {};
    }
  }

  function getUtmProperties() {
    var params = new URLSearchParams(window.location.search);
    var output = {};
    ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content'].forEach(function (key) {
      if (params.has(key)) output[key] = params.get(key);
    });
    return output;
  }

  function baseProperties(extra) {
    var referrerHost = '';
    try {
      referrerHost = document.referrer ? new URL(document.referrer).hostname : '';
    } catch (error) {}

    return cleanProperties(Object.assign(
      {
        page_path: window.location.pathname,
        page_title: document.title,
        referrer_host: referrerHost
      },
      getUtmProperties(),
      window.__kosmo_analytics_context || {},
      extra || {}
    ));
  }

  window.kosmoTrack = function (eventName, properties) {
    if (!eventName || isLocalHost()) return;
    var payload = baseProperties(properties);

    if (window.posthog && typeof window.posthog.capture === 'function') {
      window.posthog.capture(eventName, payload);
    }

    if (window.plausible && typeof window.plausible === 'function') {
      var plausibleEvents = {
        cta_clicked: true,
        outbound_link_clicked: true,
        cli_command_copied: true,
        cli_schema_command_copied: true,
        lua_example_copied: true,
        mcp_config_copied: true,
        install_command_copied: true,
        integration_page_viewed: true
      };
      if (plausibleEvents[eventName]) {
        window.plausible(eventName, { props: payload });
      }
    }
  };

  function eventForContext(context) {
    if (!context || !context.surface) return '';
    if (context.surface === 'home') return 'homepage_viewed';
    if (context.surface === 'integration') return 'integration_page_viewed';
    if (context.surface === 'integration_catalog') return 'integration_catalog_viewed';
    if (context.surface === 'integration_category') return 'integration_category_viewed';
    if (context.surface === 'mcp_client') return 'mcp_client_page_viewed';
    if (context.surface === 'comparison') return 'comparison_viewed';
    if (context.surface === 'use_case') return 'use_case_viewed';
    if (context.surface === 'site_map') return 'site_map_viewed';
    if (context.surface === 'changelog') return 'changelog_viewed';
    return '';
  }

  function trackPageView() {
    var context = window.__kosmo_analytics_context || {};
    var contextEvent = eventForContext(context);
    if (contextEvent) {
      window.kosmoTrack(contextEvent);
      return;
    }

    var path = window.location.pathname.replace(/^\\/+|\\/+$/g, '');
    if (path.indexOf('docs/') === 0 || path === 'docs') {
      var docSlug = path === 'docs' ? 'index' : path.replace(/^docs\\//, '');
      window.kosmoTrack(docSlug === 'changelog' ? 'changelog_viewed' : 'docs_page_viewed', {
        surface: 'docs',
        doc_slug: docSlug
      });
    }
  }

  function copyCode(button) {
    var code = button.dataset.copyCode || '';
    if (!code || !navigator.clipboard || typeof navigator.clipboard.writeText !== 'function') return;
    navigator.clipboard.writeText(code).then(function () {
      var original = button.textContent || 'copy';
      button.textContent = 'copied';
      setTimeout(function () { button.textContent = original; }, 1500);
      var functionName = '';
      var surface = '';
      var details = button.closest('details');
      if (details) {
        var detailsCode = details.querySelector('summary code');
        functionName = detailsCode ? detailsCode.textContent || '' : '';
        surface = details.dataset.analyticsSurface || '';
      }
      var article = button.closest('.function-entry');
      if (!functionName && article) {
        var articleCode = article.querySelector('h3 code');
        functionName = articleCode ? articleCode.textContent || '' : '';
        surface = 'cli';
      }
      window.kosmoTrack(button.dataset.analyticsEvent || 'cli_command_copied', Object.assign(
        {
          command_label: button.dataset.commandLabel || button.getAttribute('aria-label') || 'Command',
          function_name: functionName,
          surface: surface
        },
        parseJson(button.dataset.analyticsProps)
      ));
    }).catch(function () {});
  }

  function navAreaFor(link) {
    if (link.closest('header')) return 'header';
    if (link.closest('footer')) return 'footer';
    if (link.closest('.integration-toc')) return 'toc';
    if (link.closest('.integration-side-rail')) return 'side_rail';
    if (link.closest('nav')) return 'nav';
    return 'content';
  }

  document.addEventListener('click', function (event) {
    var target = event.target instanceof Element ? event.target : null;
    if (!target) return;

    var copyButton = target.closest('[data-copy-code]');
    if (copyButton) {
      copyCode(copyButton);
      return;
    }

    var explicit = target.closest('[data-analytics-event]');
    if (explicit && !explicit.matches('details')) {
      window.kosmoTrack(explicit.dataset.analyticsEvent, parseJson(explicit.dataset.analyticsProps));
      return;
    }

    var link = target.closest('a[href]');
    if (!link) return;
    var href = link.getAttribute('href') || '';
    var label = (link.textContent || link.getAttribute('aria-label') || '').trim();
    var url;
    try {
      url = new URL(href, window.location.href);
    } catch (error) {
      url = null;
    }

    if (url && url.hostname && url.hostname !== window.location.hostname) {
      window.kosmoTrack('outbound_link_clicked', {
        href: url.href,
        host: url.hostname,
        label: label
      });
      return;
    }

    if (link.closest('nav, header, footer, .integration-toc, .integration-side-rail')) {
      window.kosmoTrack('nav_link_clicked', {
        href: href,
        label: label,
        nav_area: navAreaFor(link)
      });
    }
  });

  document.addEventListener('toggle', function (event) {
    var details = event.target;
    if (!(details instanceof HTMLDetailsElement) || !details.open || !details.dataset.analyticsEvent) return;
    var key = [
      'kosmo-details',
      window.location.pathname,
      details.id || details.querySelector('summary')?.textContent || '',
      details.dataset.analyticsEvent
    ].join(':');
    try {
      if (sessionStorage.getItem(key)) return;
      sessionStorage.setItem(key, '1');
    } catch (error) {}
    var summaryCode = details.querySelector('summary code');
    window.kosmoTrack(details.dataset.analyticsEvent, Object.assign(
      {
        function_name: summaryCode ? summaryCode.textContent || '' : '',
        surface: details.dataset.analyticsSurface || ''
      },
      parseJson(details.dataset.analyticsProps)
    ));
  }, true);

  var searchTimer = 0;
  document.addEventListener('input', function (event) {
    var target = event.target instanceof HTMLInputElement ? event.target : null;
    if (!target) return;
    var label = (target.getAttribute('aria-label') || target.placeholder || target.name || '').toLowerCase();
    if (target.type !== 'search' && label.indexOf('search') === -1) return;
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(function () {
      if ((target.value || '').length < 2) return;
      window.kosmoTrack('docs_search_used', {
        surface: 'docs',
        query_length: target.value.length,
        search_surface: label || 'search'
      });
    }, 900);
  });

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', trackPageView, { once: true });
  } else {
    trackPageView();
  }
})();
`.trim();

export const analyticsHeadEntries = [
  {
    tag: 'script',
    attrs: { 'is:inline': true },
    content: plausibleSnippet,
  },
  {
    tag: 'script',
    attrs: { 'is:inline': true },
    content: posthogSnippet,
  },
  {
    tag: 'script',
    attrs: { 'is:inline': true },
    content: analyticsEventsSnippet,
  },
];
