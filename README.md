# Formula Rate Shipping for WooCommerce (v0.70.5)

[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2%2B-blue.svg)](https://www.gnu.org/licenses/gpl-2.0)
[![WordPress](https://img.shields.io/badge/WordPress-5.0%2B-21759b.svg?logo=wordpress)](https://wordpress.org/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-3.0%2B-96588a.svg?logo=woocommerce)](https://woocommerce.com/)
[![PHP](https://img.shields.io/badge/PHP-7.4%2B-777bb4.svg?logo=php)](https://www.php.net/)
[![Release](https://img.shields.io/badge/version-0.70.5-green.svg)](https://github.com/remarkablepc/Formula-Rate-Shipping/releases)
[![Sponsor @remarkablepc](https://img.shields.io/badge/Sponsor-GitHub%20Sponsors-ea4aaa?logo=githubsponsors)](https://github.com/sponsors/remarkablepc?utm_source=formula-rate-shipping&utm_medium=github-readme)
[![Donate PayPal](https://img.shields.io/badge/Donate-PayPal%20%2F%20Venmo-00457C?logo=paypal)](https://www.paypal.com/ncp/payment/EKH76RTYHH24S)

> **Quick Download**: [Download Latest formula-rate-shipping.zip (v0.70.5)](https://github.com/remarkablepc/Formula-Rate-Shipping/releases/download/v0.70.5/formula-rate-shipping.zip)  
> *Always install using the official `formula-rate-shipping.zip` release asset. Do not use GitHub's green "Code → Download ZIP" button for WordPress installation, as GitHub names the inner folder `Formula-Rate-Shipping-main`, which WordPress handles as an incompatible duplicate plugin.*

**Formula Rate Shipping** is a powerful, flexible WooCommerce shipping method plugin that lets you construct custom dynamic shipping and handling formulas, set priority rules by product category, tag, attribute, or variation, apply location and regional overrides, and test everything in real-time with an interactive simulator.

---

## Why This Plugin Was Built

> *"I have worked with ministries and non-profits for years. They always had different ways they needed to handle shipping, but nothing was ever a one-size-fits-all solution. They didn't have the budget for expensive subscription plugins or carrier APIs, but every free option out there was lacking or too limited. Store owners were stuck choosing between overcharging their supporters or eating the shipping costs on mixed orders.*
>
> *So I built the tool we always needed: completely free, open source, and flexible enough to handle real-world cart scenarios without artificial restrictions."*  
> — **David @ RemarkablePC**

---

## Standout Features

### 1. "Folded Incremental" Mixed-Cart Resolution
Standard shipping methods struggle when customers purchase mixed item types:
* **Summing every rule**: Adds each item's base rate together (e.g., $5.00 book base + $2.00 pamphlet base = $7.00 base), unnecessarily inflating costs for a single parcel.
* **Applying only the highest rule**: Charges only the primary item's rate ($5.00) and ignores secondary items entirely, leaving the merchant to absorb the handling and weight costs.

**How Folded Incremental resolves this:**
1. **Primary Item Sets the Base**: The item matching the rule with the highest base rate establishes the base package rate and handling fee.
2. **Secondary Items are Folded In**: Instead of charging their full base rate, secondary items contribute only their **marginal additional-item rate** (e.g., +$0.75).
3. **The Result**: A balanced, realistic shipping calculation for mixed-product orders.

> **Example:**
> A cart contains **1 x Hardcover Book** ($5.00 base, $3.50 additional, $3.50 handling) and **1 x Pamphlet** ($1.50 base, $0.75 additional, $1.25 handling):
> * **Base Package Fee**: $5.00 (Hardcover Base)
> * **Max Handling Fee**: $3.50 (Hardcover Handling)
> * **Folded Pamphlet Rate**: +$0.75 (Pamphlet additional rate instead of full $1.50)
> * **Total Shipping & Handling**: **$9.25** (vs. $11.25 if summed, or $8.50 if ignored).

---

### 2. Tokenized Shunting-Yard Formula Parser
Evaluates mathematical shipping rules using a dedicated parser rather than fragile string replacements or raw `eval()` calls:
* **Arithmetic**: Standard operators (`+`, `-`, `*`, `/`, `%`) and exponentiation (`^`) for non-linear curves.
* **Comparison & Logic**: `<`, `>`, `<=`, `>=`, `==`, `!=`, `AND`, `OR`, and `NOT`.
* **Set & Range Operators**: `IN ("CA", "OR", "WA")`, `BETWEEN 5 AND 50`, and `CONTAINS "Book"`.
* **Conditionals & Ternaries**: `if([weight] > 10, 15.00, 7.50)` or inline ternaries (`[qty] > 5 ? 0.00 : 4.99`).
* **Math Functions**: `min()`, `max()`, `ceil()`, `floor()`, `round()`, and `abs()`.
* **Error Handling**: Wrapped in `try/catch (\Throwable)` to ensure syntax errors fail gracefully without interrupting checkout.

---

### 3. User-Role and Account-Aware Pricing
Formulas evaluate the logged-in customer's WordPress context directly:
* Member/donor rates: `if([user="Donor"] OR [user="Subscriber"], 0.00, 5.95)`
* Registered vs. guest rates: `if([user_role="guest"], 7.50, 4.50)`
* Specific user targeting: `[user_email]` and `[user_login]`.

---

### 4. Priority Item-Specific Rules with Exclusive Trapping
* Create targeted rate and handling formulas by **Category**, **Tag**, **Attribute**, or **Variation**.
* **Exclusive Matching**: Once an item matches a higher-priority rule, it is captured and excluded from lower-priority tiers to prevent rule overlap.

---

### 5. Location and Regional Overrides
* Match by Country, State/Province, City, or ZIP code patterns (including wildcards).
* Supports 2-letter ISO codes (`US`), 3-letter codes (`USA`), and full country names (`"United States"`).
* Automatic international detection via `[is_international]`.
* Optional per-rule device (`mobile`, `desktop`) and OS filtering.

---

### 6. Interactive Real-Time Test Simulator (Card 4)
* Integrated test cart directly in the WooCommerce Admin settings.
* Test any combination of items, weights, destinations, and user roles.
* Detailed step-by-step trace calculation log displays each variable's resolved value.

---

### 7. Zero Custom Database Tables & Automated Tests
* Stores configuration cleanly within standard WooCommerce settings options.
* Full JSON backup and restore for easy migration.
* Accompanied by 334 automated unit and robustness tests across 4 suites.

---

## Quick Start Guide (3-Minute Setup)

Get dynamic shipping up and running in 4 easy steps:

1. **Install & Activate**: Download `formula-rate-shipping.zip` from GitHub releases, upload to **Plugins > Add New > Upload Plugin**, and activate it.
2. **Enable the Shipping Method**: Go to **WooCommerce > Settings > Shipping > Shipping Zones**, add a shipping zone (or click an existing zone), click **Add shipping method**, and choose **Formula Rate Shipping**.
3. **Write Your First Formula**: Open the method settings and enter your rate formula in the **Default Shipping Formula** field:
   - *Simple base + per-item*: `5.00 + ([qty] - 1) * 1.50`
   - *Weight bracket lookup*: `[table on="[weight]" tiers="1:4.50, 5:8.00, 10:12.50, default:20.00"]`
   - *Free shipping for subscribers*: `if([user="Subscriber"], 0.00, 5.99)`
4. **Test in Real Time**: Scroll to **Card 4 (Test Cart Simulator)**, add test products or set weights/destinations, and watch the formula evaluate live with complete trace logs!

---

## Maturity Status

**Version 0.70.5 -- Production-Ready Release**

This plugin has been through 35+ iterations of active development. The core formula engine and rule resolution logic are stable and battle-tested. It is suitable for production use by technically-minded store owners.

| Area | Status |
|------|--------|
| **Formula Engine** | Mature -- custom Shunting-yard parser with `if()`, ternary, logical operators, comparison operators, and 15+ variable types |
| **Rule Resolution** | Mature -- priority item rules, exclusive matching, location overrides, Folded Incremental logic |
| **Admin UI** | Polished -- collapsible summary bars, high-contrast badges, user/role autocompletion, interactive simulator |
| **Unit Tests** | Four dependency-free suites included, runnable in one command: `php tests/run-all.php` (334 tests: 110 smoke + 141 advanced + 30 integration + 53 robustness) |
| **Translations (i18n)** | Translation loader and languages directory included; locale catalogs can now be bundled |
| **Changelog** | Published |

> **Note:** As a pre-1.0 release, the settings schema may change between minor versions. Always back up your settings (Settings -> Export) before upgrading.

#### Known Limitations (from robustness testing)

The `tests/run-tests-robustness.php` suite locks in current behavior and documents edge cases. Two previously-reported issues are now **fixed**:

1. **`[fee formula="[qty] * 2.50"]` now works.** The `[fee]`/`[discount]`/`[sale]` attribute regex previously truncated at the inner `]` of `[qty]`, silently producing a `$0` "Surcharge" fee. The regex now allows bracketed placeholders inside double-quoted attribute values, so `[fee formula="[qty] * 2.50"]` correctly creates a line item for `[qty] * $2.50`. (Verified in the robustness suite.)
2. **Malformed formulas no longer throw a fatal `\ParseError`.** The evaluator's internal `@eval()` fallback could not suppress a PHP 7+ `\ParseError`, so a single malformed formula (e.g. an unclosed ternary/expression) crashed the whole request. It is now wrapped in a `try/catch ( \Throwable )` and returns a controlled `0.0` instead. (Verified in the robustness suite.)

**Remaining limitation:**

3. **Parenthesized nested ternary does not fully resolve.** `1 ? (0 ? 6.00 : 7.00) : 8.00` no longer crashes (see #2) but evaluates to `0` rather than `7.00` because the ternary tokenizer does not recognize parentheses wrapping inner branches. Use chained (non-parenthesized) ternaries instead: `0 ? 6.00 : 0 ? 7.00 : 8.00`.

---

## Requirements

- **WordPress** 5.0+ (recommended: 6.0+)
- **WooCommerce** 3.0+ (recommended: 7.0+)
- PHP 7.0+ (recommended: PHP 8.0+)

---

## Code Organization

The plugin is being migrated from a single large PHP file to feature-focused modules. Version 0.60 includes the first safe extraction: translation loading and frontend asset registration now live under `includes/`, checkout CSS lives under `assets/`, and the build packages these directories automatically. The remaining evaluator, WooCommerce runtime, admin views, and JavaScript extractions are sequenced in `docs/REFACTORING.md` and will preserve existing class names, shipping method IDs, and option keys.

---

## Formula Engine Syntax & Variables

Formulas support standard mathematical expressions and variables evaluated per matched item group in the cart.

### Available Variables

#### Cart & Item Placeholders
- `[qty]` -- Total quantity of matching items in the cart.
- `[cost]` -- Total price of matching items in the cart.
- `[weight]` -- Total weight of matching items in the cart.
- `[items]` -- Number of unique line items in the cart.

#### Filtered Placeholders (Category / Tag / Variation)
- `[qty category="X"]` -- Total quantity of items in category `X`.
- `[qty tag="X"]` -- Total quantity of items with tag `X`.
- `[qty variation="X"]` -- Total quantity of items matching variation `X`.
- `[cost category="X"]` -- Total price of items in category `X`.
- `[cost tag="X"]` -- Total price of items with tag `X`.
- `[cost variation="X"]` -- Total price of items matching variation `X`.
- `[weight category="X"]` -- Total weight of items in category `X`.
- `[weight tag="X"]` -- Total weight of items with tag `X`.
- `[weight variation="X"]` -- Total weight of items matching variation `X`.

#### Context-Aware Variables
- `[user="X"]` -- Evaluates to `1` if the current user matches `X` (by role, email, or login), else `0`. See [User Role & Identity Variables](#user-role--identity-variables) below.
- `[user_role]` -- Returns the primary user role as a text string (e.g., `subscriber`, `customer`, `administrator`, or `guest` for logged-out users).
- `[category="X"]` -- Evaluates to `1` if any matching item belongs to category slug/name `X`, else `0`.
- `[tag="X"]` -- Evaluates to `1` if any matching item has tag `X`, else `0`.
- `[variation="X"]` -- Evaluates to `1` if any matching item matches variation `X`, else `0`.
- `[product="X"]` -- Evaluates to `1` if any matching item's product name, SKU, or product/variation ID matches `X` (case-insensitive substring match for names/SKUs), else `0`.
- `[shipping_country]` -- Destination country code (e.g., `US`, `CA`, `MX`).
- `[shipping_state]` -- Destination state/region code (e.g., `CA`, `TN`, `ON`).
- `[shipping_zip]` -- Destination ZIP/postal code (e.g., `90210`, `M5V 2T6`).
- `[shipping_city]` -- Destination city name (e.g., `Los Angeles`).
- `[is_international]` -- Returns `1` when the shipping country differs from the global warehouse origin country, otherwise `0`.
- `[is_international="US"]` -- Overrides the warehouse origin comparison country for that formula and returns `1` when the destination is outside `US`. Accepts ISO codes like `US`, `CA`, `MX` or country names like `United States`, `Canada`, `Mexico`.
- `[os]` -- Current operating system, such as `Windows`, `MacOS`, `iOS`, `Android`, or `Linux`.
- `[browser]` -- Current browser, such as `Chrome`, `Firefox`, `Safari`, `Edge`, or `Opera`.
- `[device]` -- Current device type: `mobile`, `tablet`, or `desktop`.
- `[is_mobile]` -- Returns `1` for mobile/tablet devices, otherwise `0`.
- `[hour]`, `[day]`, `[month]`, `[is_weekend]` -- Current store-time context values.
- `[is_date="07-15"]`, `[is_date_range="11-25 to 11-30"]` -- Date and inclusive date-range checks.
- `[is_holiday]`, `[is_holiday="Festivus"]`, `[holiday]` -- Public-holiday and store event checks. Supports standard public holidays or custom Google Calendar, Apple iCloud, and Outlook iCal (`.ics`) URLs with optional `Holiday:` prefix filtering.
- `[notice="..."]` / `[message="..."]` / `[warning="..."]` -- Displays styled notice badges (blue 💬 chat, green ℹ️ info, or amber ⚠️ warning). Defaults to `position="cart_table"` (renders below the product table on the left side of the cart page). Supports optional `position="..."` parameter: `cart_table` (default), `under_rate` (beneath rate title), `before_shipping` (above shipping options), `after_shipping` (below shipping options), or `top` / `global` (top alert banner).

#### Standalone Order Total Line Items ([fee], [discount], [sale]) vs Internal [rate]

- **`[rate percent=10]` (Internal Rate Math)** -- Calculates 10% of subtotal and bundles it *inside* the shipping rate calculation itself without creating a separate line item. Supports `min_fee`, `max_fee`, `category`, `tag`, and `variation` filters.
- **`[fee label="..." percent=5]` (Cart Surcharge Line Item)** -- Creates a standalone positive surcharge line item in the WooCommerce Cart & Checkout Order Totals summary block (e.g. `Fuel Surcharge: +$3.50`). Supports `amount=X`, `percent=X`, `formula="..."`, and `icon="..."`. Default icon uses active WooCommerce currency symbol (`$`, `£`, `€`, `¥`).
- **`[discount label="..." amount=5.00]` (Cart Credit Line Item)** -- Creates a standalone negative credit line item in cart totals (e.g. `VIP Credit: -$5.00`) with purple discount tag.
- **`[sale label="..." percent=10]` (Cart Promo Credit Line Item)** -- Creates a standalone promo credit line item (e.g. `Flash Sale Promo: -$4.20`) with red sale tag.
- **`icon="none"` Mode** -- Renders clean, text-only cart line items without SVG icons.

#### Tiered Step Tables ([table]), Progressive Scales ([scale]) & Bounded Loops ([loop])

- **`[table on="<variable>" tiers="threshold:cost, ..."]`** -- Stepped bracket table lookup. Replaces deeply nested `if()` chains with a concise threshold-to-cost matrix:
  - `[table on="[weight]" tiers="1:4.50, 5:8.00, 10:12.50, 20:18.00, default:25.00"]` -- Flat bracket lookup by weight.
  - `[table on="[qty]" tiers="1:5.00, 3:7.50, 6:10.00, default:15.00"]` -- Tiered pricing by item count.
  - `[table on="[subtotal]" tiers="50:9.99, 100:4.99, default:0.00"]` -- Subtotal-based tiers (e.g. Free shipping over $100).
- **`[scale on="<variable>" base_cost=X base_units=Y rate=Z step=W]`** -- Progressive incremental stepper:
  - `[scale on="[weight]" base_cost=5.00 base_units=2 rate=1.50 step=1]` -- $5.00 base for first 2 lbs, then +$1.50 for each additional 1 lb.
  - `[scale on="[weight]" base_cost=25.00 base_units=50 rate=5.00 step=10]` -- Heavy freight: $25.00 base for first 50 lbs, then +$5.00 for every 10 lbs beyond 50 lbs.
  - `[scale on="[qty]" base_cost=4.00 base_units=1 rate=2.00 step=3]` -- $4.00 for 1st item, then +$2.00 for every 3 additional items.
- **`[loop var="i" from=1 to="[qty]" step=1 formula="..."]`** -- Bounded range accumulator loop (capped at 100 iterations for safety):
  - `[loop var="i" from=1 to="[qty]" formula="if(i <= 3, 5.00, 2.50)"]` -- Evaluates each step from 1 to total quantity ($5.00 each for items 1-3, $2.50 each for items 4+) and sums the total.
  - `[loop var="n" from=1 to="[qty]" formula="10.00 / n"]` -- Diminishing surcharge per additional item.

### User Role & Identity Variables

The `[user="X"]` variable is a powerful conditional that checks the **currently logged-in user** against a target value. It returns `1` (true) or `0` (false) based on the following checks, in order:

1. **User Role** -- Matches if the user has the WordPress role `X` (e.g., `subscriber`, `customer`, `administrator`, `editor`, `shop_manager`).
2. **User Email** -- Matches if the user's email address equals `X`.
3. **User Login** -- Matches if the user's login username equals `X`.

#### Examples:
- `[user="Subscriber"]` -- Returns `1` if the user has the `subscriber` role, else `0`.
- `[user="customer"]` -- Returns `1` if the user has the `customer` role (case-insensitive).
- `[user="admin@example.com"]` -- Returns `1` if the user's email is `admin@example.com`.
- `[user="johndoe"]` -- Returns `1` if the user's login is `johndoe`.

> **Note:** For logged-out (guest) users, `[user="X"]` always returns `0`. Use `[user_role]` to detect guests (returns `guest`).

### Conditional (If/Then/Else) Logic

Formulas support two styles of conditional branching:

#### 1. `if()` Function (Excel-style)
```
if(condition, true_value, false_value)
```
- Evaluates `condition`; if truthy (non-zero), returns `true_value`; otherwise returns `false_value`.
- Supports nesting and complex conditions with logical operators.

#### 2. Ternary Operator (Programmer-style)
```
condition ? true_value : false_value
```
- Same behavior as `if()`, using C-style syntax.

### Logical Operators

Conditions can be combined using logical operators:

| Operator | Description | Example |
|----------|-------------|---------|
| `AND` | Both conditions must be true | `[user="Subscriber"] AND [qty] > 3` |
| `OR` | At least one condition must be true | `[category="books"] OR [tag="fragile"]` |
| `NOT` | Negates a condition | `NOT [user="Subscriber"]` |
| `!` | Negates a condition (shorthand) | `![user="Subscriber"]` |
| `( )` | Groups conditions for precedence | `([category="books"] OR [tag="fragile"]) AND [qty] > 2` |
| `IN` | Value is in a list | `[shipping_state] IN ("CA", "OR", "WA")` |
| `BETWEEN` | Value is within a range | `[weight] BETWEEN 5 AND 50` |
| `CONTAINS` | String contains substring | `[shipping_city] CONTAINS "Los"` |

#### IN Operator
The `IN` operator checks if a value matches any item in a comma-separated list. It expands to chained `OR` comparisons:
```
[shipping_state] IN ("CA", "OR", "WA")
// Equivalent to: [shipping_state] == "CA" OR [shipping_state] == "OR" OR [shipping_state] == "WA"
```

#### BETWEEN Operator
The `BETWEEN` operator checks if a value falls within a range (inclusive):
```
[weight] BETWEEN 5 AND 50
// Equivalent to: [weight] >= 5 AND [weight] <= 50
```

#### CONTAINS Operator
The `CONTAINS` operator performs a case-insensitive substring match:
```
[shipping_city] CONTAINS "Los"
// Returns 1 if city contains "Los" (e.g., "Los Angeles"), else 0
```

### Comparison Operators

| Operator | Description | Example |
|----------|-------------|---------|
| `>` | Greater than | `[qty] > 5` |
| `<` | Less than | `[weight] < 10` |
| `>=` | Greater than or equal | `[qty] >= 6` |
| `<=` | Less than or equal | `[cost] <= 50` |
| `==` | Equal to | `[shipping_state] == "CA"` |
| `!=` | Not equal to | `[shipping_country] != "US"` |

### Math Operators

| Operator | Description | Example |
|----------|-------------|---------|
| `+` | Addition | `[qty] + 2` |
| `-` | Subtraction | `[qty] - 1` |
| `*` | Multiplication | `[qty] * 2.50` |
| `/` | Division | `[cost] / 2` |
| `%` | Modulo (remainder) | `[qty] % 6` |
| `^` | Exponentiation (power) | `[qty] ^ 2` |

### Math Functions

| Function | Description | Example |
|----------|-------------|---------|
| `min(a, b, ...)` | Returns the smallest value | `min(5.00, [qty] * 2.50)` |
| `max(a, b, ...)` | Returns the largest value | `max(10.00, [weight] * 1.50)` |
| `sum(a, b, ...)` | Returns the sum of all arguments | `sum(4.50, [weight] * 1.20, 2.00)` |
| `avg(a, b, ...)` | Returns the average of all arguments | `avg([cost], 50.00) * 0.10` |
| `clamp(x, min, max)` | Restricts `x` to the inclusive range `[min, max]` | `clamp([weight] * 2.00, 5.00, 25.00)` |
| `round(x, p)` | Rounds to `p` decimal places | `round([cost] * 0.10, 2)` |
| `ceil(x)` | Rounds up to the nearest integer | `ceil([weight]) * 1.50` |
| `floor(x)` | Rounds down to the nearest integer | `floor([qty] / 6) * 10.00` |
| `trunc(x)` | Removes decimal portion without rounding | `trunc([weight]) * 2.00` |
| `abs(x)` | Returns the absolute value | `abs([qty] - 10) * 0.50` |
| `sqrt(x)` | Square root of `x` | `sqrt([volume]) * 1.50` |
| `log(x)` | Base-10 logarithm of `x` | `log([cost] + 1) * 5.00` |
| `ln(x)` | Natural logarithm of `x` | `ln([weight] + 1) * 3.00` |
| `exp(x)` | Exponential (`e` raised to power `x`) | `exp([qty] * 0.05) + 3.00` |
| `pow(a, b)` | Exponentiation (alias for `a ^ b`) | `pow([weight], 0.8) * 4.00` |
| `mod(a, b)` | Modulo remainder (alias for `a % b`) | `mod([qty], 6) * 1.50` |

#### Alias Functions
The formula engine includes functional aliases for operator shorthand:
- **`pow(a, b)` ↔ `a ^ b`**: Computes `a` raised to the power `b` (e.g. `pow([weight], 2)` is identical to `[weight] ^ 2`).
- **`mod(a, b)` ↔ `a % b`**: Returns the remainder of division (e.g. `mod([qty], 6)` is identical to `[qty] % 6`).

---

## Formula Examples

### 1. Tiered Quantity Shipping
```
1.50 + ([qty] - 1) * 0.75
```
*Charges $1.50 for the first item, plus $0.75 for each additional item.*

### 2. Category-Conditional Shipping (Ternary)
```
[category="children-of-promise-children"] ? 4.50 + ([qty] - 1) * 3.00 : 5.00 + ([qty] - 1) * 3.50
```
*Charges $4.50 + $3.00/additional for Children of Promise, else $5.00 + $3.50/additional.*

### 3. Free Shipping for Subscribers (if() Function)
```
if([user="Subscriber"], 0.00, 5.00)
```
*Free shipping for Subscribers, $5.00 for everyone else.*

### 4. Free Shipping for Subscriber Hardcovers (if() + AND)
```
if([user="Subscriber"] AND [variation="Hardcover"], 0.00, 7.50)
```
*Free shipping when a Subscriber buys a Hardcover, $7.50 otherwise.*

### 5. Non-Subscriber Surcharge (if() + NOT)
```
if(![user="Subscriber"], 10.00, 0.00)
```
*$10.00 for non-subscribers only, free for Subscribers.*

### 6. State-Based Shipping (if() + Comparison)
```
if([shipping_state] == "CA", 15.00, 10.00)
```
*$15.00 for California, $10.00 for all other states.*

### 7. Weight-Based Shipping
```
[weight] * 1.50
```
*$1.50 per pound.*

### 8. Partial Box Detection (if() + Modulo)
```
if([qty] % 6 > 0, 15.00, 10.00)
```
*$15.00 if the cart has a partial box (not a multiple of 6), else $10.00.*

### 9. Heavy Package Surcharge (if() + Comparison)
```
if([weight] > 50, 25.00, 10.00)
```
*$25.00 if over 50 lbs, else $10.00.*

### 10. Category-Based Rate Selection (if() + Category)
```
if([category="Books"] > 0, [qty] * 3.50, [qty] * 2.50)
```
*$3.50 per item for books, $2.50 per item for non-books.*

### 11. Subscriber Discount (Ternary)
```
[user="Subscriber"] ? 0.00 : [qty] * 2.50
```
*Free for subscribers, else per-item rate.*

### 12. Percentage Rate with Minimum
```
[rate percent=10 min_fee=5]
```
*10% of cart subtotal, but never less than $5.00.*

### 12b. Flat International Surcharge
```
if([is_international], 15.00, 0.00)
```
*$15.00 when the destination country is outside the warehouse origin country, otherwise $0.00.*

### 12c. Flat International Surcharge With Override
```
if([is_international="US"], 15.00, 0.00)
```
*$15.00 when the destination country is outside `US`, regardless of the global warehouse setting. You can also use country names like `Mexico` or `Canada` in the override form.*

### 13. Filtered Quantity
```
[qty category="mini-books"] * 0.75
```
*$0.75 per mini-book in the cart.*

### 14. Guest vs. Registered User
```
if([user_role] == "guest", 8.00, 5.00)
```
*$8.00 for guests, $5.00 for registered users.*

### 15. Clamped Per-Item Rate (min + max)
```
max(5.00, min(25.00, [qty] * 3.00))
```
*$3.00 per item, never less than $5.00, never more than $25.00.*

### 16. Bulk Discount Curve (Exponentiation)
```
5.00 * [qty] ^ 0.85
```
*Diminishing per-item cost as quantity increases -- $5.00 for 1, ~$9.00 for 2, ~$12.50 for 3.*

### 17. Weight Rounded Up (ceil)
```
ceil([weight]) * 2.00
```
*$2.00 per pound, rounding weight up to the next whole pound (carrier-style).*

### 18. West Coast Shipping (IN Operator)
```
if([shipping_state] IN ("CA", "OR", "WA"), 15.00, 10.00)
```
*$15.00 for West Coast states, $10.00 elsewhere.*

### 19. Weight Range (BETWEEN Operator)
```
if([weight] BETWEEN 5 AND 50, 12.00, 8.00)
```
*$12.00 for packages between 5-50 lbs, $8.00 otherwise.*

### 20. City Substring Match (CONTAINS Operator)
```
if([shipping_city] CONTAINS "Los", 20.00, 10.00)
```
*$20.00 for cities containing "Los" (e.g., Los Angeles), $10.00 otherwise.*

### 21. Product Name Match
```
if([product="Hardcover"], 5.00, 3.00)
```
*$5.00 for any product with "Hardcover" in its name, $3.00 otherwise.*

---

## Installation & Setup

1. Download `formula-rate-shipping.zip` from the [Latest Release](https://github.com/remarkablepc/Formula-Rate-Shipping/releases/latest).
2. Log into your WordPress Admin Dashboard.
3. Navigate to **Plugins** -> **Add New** -> **Upload Plugin**.
4. Upload `formula-rate-shipping.zip` and click **Install Now**, then **Activate**.
5. Go to **WooCommerce** -> **Settings** -> **Shipping** -> **Shipping Zones** to add and configure **Formula Rate Shipping**.

> **Tip on WooCommerce Shipping Cache:** WooCommerce caches shipping rates in customer sessions. When updating settings or testing live rates, change the customer ZIP code on the checkout page or clear transients under **WooCommerce -> Status -> Tools -> Clear transients**.

---

## Changelog

### v0.54
- Equalized Country and State field heights (38px) with `flex-wrap: nowrap` so `ALL` chips and text editors remain inline on a single line.
- Enforced autocomplete for Country and State entries across location rules and simulator address controls (no native `<select>` dropdowns).
- Applied sentence-case tooltip for state selection: *"State selector only available if one country is selected."*
- Updated formula auto-parser validation to run strictly on `Enter` keypress or when losing focus (`blur`), preventing constant execution on scroll or typing.
- Synchronized Export and Import handlers to cover all current settings keys (`auto_inject`, `round_rates`, `holiday_country`, `location_rule_resolution`, etc.).

### v0.45
- Implemented `[message="..."]` and `[notice="..."]` shipping-rate messages for the classic WooCommerce cart and checkout.
- Added simulator support for `^`, `min()`, `max()`, `round()`, `ceil()`, `floor()`, and `abs()`.
- Synchronized the in-plugin and README references for `IN`, `BETWEEN`, `CONTAINS`, exponentiation, math functions, environment variables, and message tags.

### v0.35
- Added `[product="X"]` placeholder for product name matching in formulas.
- Added `IN`, `BETWEEN`, and `CONTAINS` logical operators for cleaner formula expressions.
- Updated admin simulator to support all new operators and placeholders.

### v0.34
- Added explicit user matching for Section 2 rules across runtime evaluation and the admin simulator.
- Reworked Section 2 into compact mini-tab target groups and split the old `Device / OS` input into separate Device and OS fields with Windows and macOS alias support.
- Added per-rule enable/disable switches for location/user rules and item rules.
- Simplified backup and restore to JSON only, matching the admin UI and README.

---

## Supporting Open Source Development

Formula Rate Shipping is 100% free and open-source software under the GPLv2 or later license. There are no paid feature paywalls, subscription nag screens, or telemetry tracking.

If this plugin saves you time, simplifies your store configuration, or solved your shipping challenges, please consider sponsoring ongoing development:

* [💖 Sponsor @remarkablepc on GitHub Sponsors](https://github.com/sponsors/remarkablepc?utm_source=formula-rate-shipping&utm_medium=github-readme)
* [☕ Donate via PayPal / Venmo](https://www.paypal.com/ncp/payment/EKH76RTYHH24S)

---

## License & Copyright

Copyright (c) 2026 RemarkablePC / David Hewitt.

Licensed under the [GNU General Public License v2.0 or later](LICENSE). Free for personal and commercial WooCommerce stores.