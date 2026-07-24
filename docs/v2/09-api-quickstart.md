# V2 API Quickstart (End-to-End)

## 1) Get extension token

`POST /api/v2/auth/extension-token`

```json
{
  "email": "user@example.com",
  "password": "your-password",
  "device_name": "LinkedEmpire V2 Extension"
}
```

Use returned `token` as `Authorization: Bearer <token>`.

## 2) Create Unipile hosted auth link

`POST /api/v2/integration-accounts/hosted-auth-link`

```json
{
  "provider": "linkedin",
  "redirect_url": "https://your-app.test/callback"
}
```

## 3) Search leads and persist

`POST /api/v2/leads/search`

```json
{
  "keywords": "sales leader",
  "limit": 20,
  "persist_results": true
}
```

## 4) Send invitation

`POST /api/v2/outreach/invite`

```json
{
  "recipient_id": "linkedin_member_id",
  "message": "Happy to connect."
}
```

## 5) Start chat and send message

`POST /api/v2/outreach/start-chat`

```json
{
  "attendee_ids": ["linkedin_member_id"],
  "text": "Hello from v2."
}
```

`POST /api/v2/outreach/message`

```json
{
  "chat_id": "chat_id_here",
  "text": "Follow-up message."
}
```

## Notes

- By default `.env.example` sets `UNIPILE_MOCK=true` for local development.
- Set `UNIPILE_MOCK=false` and configure `UNIPILE_API_KEY` for live Unipile calls.
