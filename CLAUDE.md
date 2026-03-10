# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

SimpleChat is a real-time chat application built with a Laravel backend and Next.js frontend, using Azure Web PubSub for WebSocket messaging.

## Tech Stack

- **Backend**: Laravel (PHP) - REST API and WebSocket token management
- **Frontend**: Next.js with TypeScript, Tailwind CSS, App Router
- **Real-time**: Azure Web PubSub for WebSocket communication

## Development Commands

### Backend (Laravel)
```bash
# Start development server
cd backend && php artisan serve

# Run migrations
php artisan migrate

# Run tests
php artisan test
```

### Frontend (Next.js)
```bash
# Start development server
cd frontend && npm run dev

# Build for production
npm run build

# Run linting
npm run lint

# Run tests
npm test
```

## Architecture Notes

- Azure Web PubSub handles WebSocket connections and message broadcasting
- Backend generates JWT tokens for clients to authenticate with Web PubSub
- Frontend connects to Web PubSub using `@azure/web-pubsub-client` SDK
