# Azure Event Handler Configuration Guide

## Overview

This guide explains how to configure Azure Web PubSub Event Handler to enable true real-time messaging in SimpleChat.

## Problem Statement

Without configuring the Event Handler, messages are delivered via polling (every 2 seconds) rather than true real-time WebSocket delivery. This is because:

1. Backend broadcasts messages to the `default` group
2. Clients connect to Azure Web PubSub but are NOT automatically added to the `default` group
3. The event handler code exists (`WebPubSubEventHandler.php`) but Azure doesn't call it

## Solution

Configure Azure Web PubSub to call your event handler endpoint when clients connect. This will automatically add clients to the `default` group, enabling true real-time messaging.

## Step-by-Step Instructions

### 1. Navigate to Azure Web PubSub Resource

1. Go to [Azure Portal](https://portal.azure.com)
2. Find and select your Web PubSub resource (e.g., `qaautoallies`)
3. Go to **Settings** → **Event Handler**

### 2. Add Event Handler

Click **Add** to create a new event handler with the following settings:

#### For Local Development:

| Setting | Value |
|---------|-------|
| **Name** | `simplechat-handler` |
| **URL Template** | `http://localhost:8000/api/webpubsub/events` |
| **System Events** | Check `connected`, `disconnected` |
| **User Event Pattern** | Leave empty (or `*` for all) |

#### For Production:

| Setting | Value |
|---------|-------|
| **Name** | `simplechat-handler` |
| **URL Template** | `https://your-domain.com/api/webpubsub/events` |
| **System Events** | Check `connected`, `disconnected` |
| **User Event Pattern** | Leave empty (or `*` for all) |

### 3. Configure Authentication (Optional but Recommended)

For production, configure authentication:

1. Under **Authentication**, select **Managed Identity** or **Azure AD**
2. Or use **URL parameters** with query string authentication

### 4. Save the Configuration

Click **Create** or **Save** to apply the changes.

### 5. Test the Configuration

#### Step A: Check Backend Logs

```bash
# Watch Laravel logs
tail -f backend/storage/logs/laravel.log
```

You should see logs like:
```
[2026-03-10 XX:XX:XX] local.INFO: WebPubSub event received
[2026-03-10 XX:XX:XX] local.INFO: Processing WebPubSub event {"type":"connected"}
[2026-03-10 XX:XX:XX] local.INFO: Client connected {"connectionId":"..."}
[2026-03-10 XX:XX:XX] local.INFO: Azure PubSub added connection to group
```

#### Step B: Test Real-Time Messaging

1. Open two browser windows (use incognito mode for separate sessions)
2. Login as different users:
   - Browser A: `test@example.com`
   - Browser B: `testuser2@example.com`
3. Send a message from Browser A
4. **Expected**: Message instantly appears in both browsers

#### Step C: Disable Polling (Optional)

Once real-time is verified, you can remove the polling fallback from `frontend/components/ChatWindow.tsx`:

```typescript
// Remove lines 45-100 (the polling useEffect)
```

## Troubleshooting

### No logs appearing in Laravel logs

1. Verify the Event Handler URL is correct
2. Check Azure Portal → Event Handler → **Events** tab for delivery status
3. Ensure your backend is accessible from Azure (not blocked by firewall)

### Events not being delivered

1. Check Azure Event Handler **Events** tab for error messages
2. Verify your endpoint returns HTTP 200 (the current handler does this)
3. For local development, use a tunnel service like ngrok:
   ```bash
   ngrok http 8000
   ```
   Then update Event Handler URL to the ngrok URL

### Clients not receiving messages

1. Check backend logs for "Added connection to default group"
2. Verify the group name matches (`default` in both broadcaster and event handler)
3. Check browser console for WebSocket connection errors

## Architecture Diagram

```
┌─────────────────┐
│  Browser A      │
│  (User A)       │
└────────┬────────┘
         │ Connect
         ▼
┌─────────────────────────────────────────────────────┐
│              Azure Web PubSub                        │
│                                                      │
│  ┌────────────────────────────────────────────────┐ │
│  │  Event Handler (configured in Azure Portal)    │ │
│  │  ↓                                              │ │
│  │  POST /api/webpubsub/events                    │ │
│  │  (to your Laravel backend)                     │ │
│  └────────────────────────────────────────────────┘ │
│                      │                               │
│                      ▼                               │
│  ┌────────────────────────────────────────────────┐ │
│  │  Backend Event Handler                        │ │
│  │  (WebPubSubEventHandler.php)                  │ │
│  │  - Adds connection to "default" group         │ │
│  └────────────────────────────────────────────────┘ │
│                                                      │
│  ┌────────────────────────────────────────────────┐ │
│  │  "default" Group                               │ │
│  │  ✓ Browser A                                   │ │
│  │  ✓ Browser B                                   │ │
│  └────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────┘
         │ Broadcast to "default" group
         ▼
┌─────────────────┐     ┌─────────────────┐
│  Browser A      │     │  Browser B      │
│  (instant!)     │     │  (instant!)     │
└─────────────────┘     └─────────────────┘
```

## Files Reference

| File | Purpose |
|------|---------|
| `backend/app/Http/Controllers/WebPubSubEventHandler.php` | Handles Azure events |
| `backend/routes/web.php` | Event handler route (line 41) |
| `backend/app/Services/AzurePubSubPublisher.php` | Broadcasts to "default" group |
| `frontend/components/ChatWindow.tsx` | Chat UI with polling fallback |

## Next Steps

1. Configure Azure Event Handler (this guide)
2. Verify real-time messaging works
3. Optionally remove polling fallback from ChatWindow.tsx
4. Deploy to production with production Event Handler URL
