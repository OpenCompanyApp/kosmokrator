# Lua Script Examples

## Example: Aggregate Analytics Dashboard

Query multiple metrics and format a summary:

```lua
local site = "example.com"

-- Get aggregate stats
local stats = app.integrations.plausible.query_stats({
    site_id = site,
    metrics = {"visitors", "pageviews", "bounce_rate", "visit_duration"},
    date_range = "30d"
})
print("=== Monthly Summary for " .. site .. " ===")
print("Visitors: " .. tostring(stats.results.visitors))
print("Pageviews: " .. tostring(stats.results.pageviews))
print("Bounce Rate: " .. string.format("%.1f%%", stats.results.bounce_rate))
print("Avg Duration: " .. string.format("%.0fs", stats.results.visit_duration))

-- Get top pages
local pages = app.integrations.plausible.query_stats({
    site_id = site,
    metrics = {"visitors", "pageviews"},
    date_range = "30d",
    dimensions = {"event:page"},
    order_by = '[["pageviews", "desc"]]',
    limit = 10
})

print("\n=== Top 10 Pages ===")
for i, row in ipairs(pages.rows or {}) do
    print(string.format("%2d. %-40s %5d visitors  %5d pageviews",
        i, row["event:page"] or "?", row.visitors or 0, row.pageviews or 0))
end
```

## Example: Multi-Step Data Pipeline

Fetch data from one integration, transform it, and use it with another:

```lua
-- Step 1: Get crypto prices
local prices = app.integrations.coingecko.get_price({
    ids = {"bitcoin", "ethereum", "solana"},
    vs_currencies = {"usd", "eur"}
})

-- Step 2: Format as a table
print("=== Current Crypto Prices ===")
for id, data in pairs(prices) do
    local usd = data.usd or 0
    local eur = data.eur or 0
    print(string.format("%-12s $%-12s €%-12s", id, tostring(usd), tostring(eur)))
end

-- Step 3: Get market cap data
local market = app.integrations.coingecko.get_market_data({
    ids = {"bitcoin", "ethereum"},
    vs_currency = "usd"
})

print("\n=== Market Data ===")
for i, coin in ipairs(market or {}) do
    print(string.format("%-12s Market Cap: $%.0fB  Change 24h: %+.1f%%",
        coin.symbol or "?",
        (coin.market_cap or 0) / 1e9,
        coin.price_change_percentage_24h or 0))
end
```

## Example: Multi-Account Operations

When you have multiple accounts configured for the same integration, use account-specific namespaces:

```lua
-- Compare analytics across two Plausible instances
local accounts = {"work", "personal"}

for _, account in ipairs(accounts) do
    local ok, result = pcall(function()
        return app.integrations.plausible[account].query_stats({
            site_id = "example.com",
            metrics = {"visitors"},
            date_range = "7d"
        })
    end)

    if ok then
        local visitors = result.results and result.results.visitors or 0
        print(string.format("[%s] %-12s %5d visitors/week", account, "example.com", visitors))
    else
        print(string.format("[%s] Error: %s", account, tostring(result):sub(1, 60)))
    end
end
```

### Named vs Default Account

```lua
-- These are equivalent when only one account is configured:
app.integrations.plausible.query_stats({...})           -- flat namespace → default account
app.integrations.plausible.default.query_stats({...})   -- explicit default

-- Use named accounts when multiple are configured:
app.integrations.plausible.work.query_stats({...})      -- "work" account credentials
app.integrations.plausible.personal.query_stats({...})  -- "personal" account credentials
```

## Example: Error Handling in Batch Operations

Process multiple items with individual error handling:

```lua
local sites = {"example.com", "docs.example.com", "blog.example.com"}

for _, site in ipairs(sites) do
    local ok, result = pcall(function()
        return app.integrations.plausible.query_stats({
            site_id = site,
            metrics = {"visitors"},
            date_range = "7d"
        })
    end)

    if ok then
        local visitors = result.results and result.results.visitors or 0
        print(string.format("%-30s %5d visitors/week", site, visitors))
    else
        print(string.format("%-30s Error: %s", site, tostring(result):sub(1, 60)))
    end
end
```

## Example: Working with Nested Data

Extract and transform nested API responses:

```lua
local result = app.integrations.plausible.query_stats({
    site_id = "example.com",
    metrics = {"visitors"},
    date_range = "30d",
    dimensions = {"visit:country"},
    order_by = '[["visitors", "desc"]]',
    limit = 10
})

-- Build a simple bar chart
local max_visitors = 0
for _, row in ipairs(result.rows or {}) do
    if (row.visitors or 0) > max_visitors then
        max_visitors = row.visitors
    end
end

print("\nTop Countries by Visitors:\n")
for _, row in ipairs(result.rows or {}) do
    local country = row["visit:country"] or "??"
    local visitors = row.visitors or 0
    local bar_len = math.floor((visitors / max_visitors) * 30)
    local bar = string.rep("█", bar_len)
    print(string.format("  %-4s %5d %s", country, visitors, bar))
end
```

## Example: Cross-Account Aggregation

Aggregate data across multiple accounts of the same integration:

```lua
local total_visitors = 0
local account_data = {}

-- Collect from all accounts
local accounts = {"work", "personal", "client"}
for _, account in ipairs(accounts) do
    local ok, result = pcall(function()
        return app.integrations.plausible[account].query_stats({
            site_id = "example.com",
            metrics = {"visitors"},
            date_range = "30d"
        })
    end)

    if ok then
        local visitors = result.results and result.results.visitors or 0
        account_data[account] = visitors
        total_visitors = total_visitors + visitors
    end
end

-- Print summary
print("=== Cross-Account Analytics Summary ===\n")
for account, visitors in pairs(account_data) do
    local pct = total_visitors > 0 and (visitors / total_visitors * 100) or 0
    print(string.format("  %-12s %5d visitors (%5.1f%%)", account, visitors, pct))
end
print(string.format("\n  %-12s %5d visitors (total)", "TOTAL", total_visitors))
```
