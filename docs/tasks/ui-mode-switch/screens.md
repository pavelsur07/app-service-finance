# UI mode screen matrix

| Screen | Route | Legacy | App | Notes |
|---|---|---:|---:|---|
| Customer dashboard | `/` | Existing Tabler template | Implemented UI Kit template | Pilot; selected by `UiModeResolver` |
| Other customer screens | Existing routes | Supported | Not implemented | Always render their existing legacy templates |
| Admin | `/admin/*` | Not switchable | Existing app-only shell | Outside the customer switch |
| Authentication | Login/invite flows | Layout-specific | Existing modern auth where configured | Not switchable |

The repository does not contain a designer-provided dashboard file under
`site/screens/`. The app dashboard therefore composes existing UI Kit
`app-header`, `app-shell`, `sidebar`, `page-scaffold`, `kpi`, and `money`
patterns without introducing new design-system components.
