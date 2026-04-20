# Technical Design: Window Manager (wm) Improvements

**Status:** Proposed Architecture  
**Scope:** `semitexa-platform-wm` module

---

## 1. LLM Context & Summary
This document outlines improvements to the Window Manager (WM) in the Semitexa platform. The current MVP provides basic iframe rendering and app registration. The proposed design focuses on full window management features (drag/resize, state persistence, Z-index stacking), modularizing the frontend JS, and enhancing SSE (Server-Sent Events) for real-time synchronization across multiple browser tabs.

---

## 2. Current Implementation Analysis

### 2.1 Backend (PHP)
- **Architecture:** CQRS-based (Payload/Handler).
- **Registration:** `#[AsWmApp]` attribute and `WmAppRegistry`.
- **State:** Session-based storage in `WmStateService`.
- **Events:** `WmEventBus` via Server-Sent Events (SSE).

### 2.2 Frontend (JS/UI)
- **Technologies:** Vanilla JS (`wm-shell.js`) + Web Components (`<wm-window-frame>`).
- **Shortcomings:** Monolithic JS, inline CSS strings, lacks Z-index management, and no backend synchronization for position/size changes.

---

## 3. Proposed Improvements

### 3.1. Frontend Shell Architecture
Split `wm-shell.js` into ES modules:
- `WindowManager.js`: Logic for Z-index, state, and window lifecycle.
- `WindowFrame.js`: UI component for dragging, resizing, and rendering.
- `Taskbar.js`: New component for window minimization and switching.

### 3.2. Full Window Control & Focus
- **Z-Index Stack:** Bringing windows to the foreground on click/focus.
- **Bounds Synchronization:** Asynchronously sending `PATCH /api/platform/wm/windows/:id` with new coordinates/sizes after drag/resize events.
- **Real-time Sync:** Broadcasting `window.update` events via SSE to synchronize all open browser tabs.

### 3.3. State Persistence
- Store `bounds.x, y, w, h` and `state` (`normal`, `minimized`, `maximized`) in the backend.
- Reloading the page restores windows to their previous positions and states.

### 3.4. Multi-Layer Window Grouping (Tabbing)
- **Grouping:** Merging multiple windows into a single tabbed group.
- **Interactions:** Drag-and-drop to attach/detach tabs from groups.
- **Broadcast:** Syncing group state via `window.group`/`window.ungroup` SSE events.

---

## 4. Advanced SSE Integration
- **Cross-App Communication:** Exchanging messages between isolated apps via the server bus.
- **Security Gateway:** Forcing child window creation (from iframes) to go through backend requests and SSE triggers, mitigating CORS/Security Policy issues.

---

## 5. Client SDK (`wm-sdk.js`)
Create a lightweight library for apps running inside the WM:
```javascript
import { WmClient } from '@semitexa/wm-sdk';
const wm = new WmClient();
wm.open('customer', { id: 123 }).then(id => console.log(id));
wm.closeSelf();
```

---

## 6. Implementation Roadmap

| Phase | Description | Status |
|:---|:---|:---|
| **Phase 1** | Align folder structure with `MODULE_STRUCTURE.md`. | [x] |
| **Phase 2** | Expand `WmStateService` with bounds/state data. | [x] |
| **Phase 3** | Implement Z-index and drag/resize event handlers. | [x] |
| **Phase 4** | Create Taskbar and minimization logic. | [x] |
| **Phase 5** | Implement Window Grouping (Tabbing system). | [x] |
| **Phase 6** | Design and distribute Client SDK. | [x] |
| **Phase 7** | Refactor frontend into ES modules + external CSS. | [x] |
