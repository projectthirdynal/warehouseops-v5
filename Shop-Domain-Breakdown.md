# Shop Domain Breakdown

## Critical

### Phase 1 - Core Conversation Management

#### Models

- **Conversation.php**
  - Core conversation model with status management (NEW, ASSIGNED, AWAITING_CUSTOMER, RESOLVED, ARCHIVED, ORDER_CREATED, AWAITING_PAYMENT, AWAITING_CONFIRMATION)
  - Status transition rules and validation
  - SLA thresholds configuration (60min for NEW, 240min for ASSIGNED, 1440min for AWAITING_CUSTOMER)
  - Relationships to Customer, FacebookPage, User (assigned agent), CustomerIdentity, Message, Tag
  - Scopes for archivable, archived, and compressed conversations
  - Fields: archived_at, compressed_at, message_count for archiving feature

- **Message.php**
  - Message model for conversation messages
  - Supports inbound/outbound directions
  - Message types: text, quick_reply, postback, image, voice, video, file, fallback
  - Attachments, reactions, phone candidates
  - Delivery tracking (delivered_at, read_at)
  - External message ID for Meta sync

- **CustomerIdentity.php**
  - Customer identity model for multi-platform customer tracking
  - Provider types: facebook, instagram, whatsapp
  - Links to Customer model
  - Display name and metadata

#### Services

- **FacebookConnectorService.php**
  - Meta/Facebook OAuth integration
  - Page connection and webhook subscription
  - Token validation and refresh
  - Message sending (text, quick replies, typing indicators)
  - Permission revocation handling
  - Required scopes: pages_show_list, pages_manage_metadata, pages_messaging, pages_manage_engagement

- **MetaConversationIngestor.php**
  - Processes Meta webhook events into conversations and messages
  - Handles message events, sender actions (typing, seen), reactions, delivery, read receipts
  - Phone detection and customer identity linking
  - Auto-assignment delegation to AutoAssignmentService
  - Sentiment analysis integration
  - Page comment processing
  - Message type classification

### Phase 2 - Assignment and SLA Management

#### Services

- **AutoAssignmentService.php**
  - 4 assignment strategies: round_robin, skill_based, workload, hybrid
  - Page-specific assignment rules support
  - Eligibility filtering (enabled, available, shift hours, queue limits)
  - Skill-based scoring (product, region, category)
  - Bulk assignment support (up to 100 conversations)
  - Assignment history tracking
  - Settings via SiteSetting (strategy, enabled, queue limits, shift hours)

- **ConversationSlaService.php**
  - SLA threshold monitoring and breach detection
  - Stats: active conversations, breached count, warning count, breach rate
  - First response and resolution time metrics
  - 7-day trend analysis
  - Agent performance breakdown
  - Configurable thresholds and warning percent
  - Breach alert notifications (log channel)

## High

### Phase 1 - Product Recommendations

#### Services

- **ProductRecommendationService.php**
  - 3 recommendation algorithms: hybrid (default), item_based, content_based
  - Item-based: cosine similarity collaborative filtering
  - Content-based: category/brand/price similarity
  - Hybrid: 65% item-based + 35% content-based
  - Customer-specific recommendations
  - Configurable settings (algorithm, cache, result count, min co-occurrence, lookback days)
  - Statistics: coverage %, top co-occurring pairs, top recommended products
  - Cache management (5-minute TTL)

### Phase 2 - Broadcast Campaigns

#### Models

- **BroadcastCampaign.php**
  - Campaign model for bulk messaging
  - Status: draft, scheduled, sending, completed, cancelled
  - A/B testing support (split_type, split_percentage)
  - Targeting configuration
  - Statistics: total_recipients, sent_count, replied_count, failed_count

- **BroadcastVariant.php**
  - Message variant for A/B testing
  - Quick replies support
  - Per-variant statistics (sent, delivered, read, replied, failed)

- **BroadcastRecipient.php**
  - Individual recipient tracking
  - Status: pending, sent, failed, skipped
  - Error message logging

#### Services

- **BroadcastCampaignService.php**
  - Recipient preview with targeting
  - Campaign creation with variants
  - Campaign sending with A/B split
  - Targeting query builder (page, agent, status, tags, risk level, opt-in, orders)
  - Campaign cancellation
  - Statistics and recent campaigns

### Phase 3 - Conversation Archiving

#### Services

- **ConversationArchiveService.php**
  - Conversation archiving (status change to ARCHIVED)
  - Message compression to JSON storage
  - Archive restoration from JSON
  - Bulk operations (archive, compress)
  - Configurable settings (archive_after_days, compress_after_days, batch_size)
  - Statistics: archived count, compressed count, archivable count, bytes saved
  - Storage disk configuration

### Phase 4 - Cart Templates

#### Models

- **CartTemplate.php**
  - Reusable cart configurations
  - Fields: items, courier_code, shipping_fee, discount_amount, tax_rate, remarks
  - Sharing support (is_shared, allowed_roles)
  - Cloning support (cloned_from)
  - Usage tracking (last_used_at)
  - Scopes: sharedOrOwned, accessibleTo

## Medium

### Phase 1 - Rich Media Templates

#### Services

- **RichMediaTemplateService.php**
  - Validation for button, card, and carousel templates
  - Facebook Messenger-compatible payload building
  - Carousel generation from products
  - Statistics by media type
  - Template preview without saving

### Phase 2 - Gamification

#### Models

- **Badge.php**
  - Achievement badges with criteria
  - Categories: conversations, resolution, sales, streaks, performance, sentiment
  - Criteria types: conversations_handled, conversations_resolved, orders_created, streak_days, avg_response_time, positive_sentiment

- **AgentBadge.php**
  - User-badge relationship with awarded_at timestamp

- **Milestone.php**
  - Progress milestones with metrics
  - Period types: all_time, daily
  - Optional reward badge

- **AgentMilestone.php**
  - User-milestone tracking with progress

- **AgentStreak.php**
  - Activity streak tracking
  - current_streak and longest_streak

#### Services

- **GamificationService.php**
  - Default badges and milestones seeding
  - Badge awarding based on metrics
  - Milestone progress tracking
  - Streak tracking (daily activity)
  - Leaderboard generation
  - Agent profile with badges, streak, milestones
  - Bulk check and award for all agents
  - Settings: enabled, auto_award, auto_track, grace period, leaderboard size

### Phase 3 - Courier Export

#### Models

- **CourierExportBatch.php**
  - Export batch management
  - Status: ready, processing, completed
  - File tracking (path, size, hash)
  - Region support
  - Statistics: row_count, sent_count, failed_count

- **CourierExportRow.php**
  - Individual export row
  - Order mapping
  - Status: exported, failed
  - Error message logging

- **BatchItemErrorLog.php**
  - Error logging for export failures
  - Resolution tracking

#### Services

- **CourierExportService.php**
  - Batch creation from orders
  - CSV generation with courier-specific schemas
  - Validation for export blocking errors
  - Rebuild failed rows
  - Full batch rebuild
  - CSV preview
  - Format info (headers, field count)
  - Notification on batch ready

## Low

### Phase 1 - Supporting Services

#### Services

- **PhoneDetectionService.php**
  - Phone number extraction from text
  - Multiple phone formats support
  - Normalization to standard format

- **CustomerIdentityService.php**
  - Customer identity upsert
  - Phone-based identity lookup
  - Display name management
  - Metadata tracking

- **SentimentAnalysisService.php**
  - Text sentiment analysis (positive, neutral, negative)
  - Flagged words detection
  - Configurable thresholds
  - Auto-flagging support
  - Sentiment score calculation

### Phase 2 - Additional Models

#### Models

- **FacebookAccount.php**
  - User's Facebook account connection
  - Token management (access_token, token_expires_at, data_access_expires_at)
  - Connection status tracking
  - Reconnect required flag

- **FacebookPage.php**
  - Facebook page connection
  - Page access token
  - Webhook subscription status
  - Connection status
  - Metadata (tasks, subscribed fields)

- **FacebookWebhookEvent.php**
  - Webhook event logging
  - Payload storage
  - Processing status (processed_at, error_message)
  - Event ID tracking

- **Tag.php**
  - Conversation tags
  - Color coding
  - Slug-based lookup

- **ConversationAssignmentHistory.php**
  - Assignment change tracking
  - Agent, reason, timestamp

- **ConversationStatusHistory.php**
  - Status change tracking
  - From/to status, reason, timestamp

- **PageAssignmentRule.php**
  - Page-specific assignment rules
  - User assignment
  - Active status

- **AgentProfile.php**
  - Agent skill profiles
  - Skills: product, region, category
  - Shift hours configuration
  - Queue limits
  - Last assignment tracking

## Directory Structure

```
app/Domain/Shop/
├── Console/
├── CourierCsv/
│   ├── CourierCsvColumn.php
│   ├── CourierCsvSchema.php
│   ├── CourierCsvSchemaRegistry.php
│   └── CourierCsvValidator.php
├── Http/
│   └── MetaWebhookController.php
├── Jobs/
│   └── ProcessMetaWebhookEvent.php
├── Models/
│   ├── AgentProfile.php
│   ├── AgentBadge.php
│   ├── AgentMilestone.php
│   ├── AgentStreak.php
│   ├── Badge.php
│   ├── BatchItemErrorLog.php
│   ├── BroadcastCampaign.php
│   ├── BroadcastRecipient.php
│   ├── BroadcastVariant.php
│   ├── CartTemplate.php
│   ├── Conversation.php
│   ├── ConversationAssignmentHistory.php
│   ├── ConversationStatusHistory.php
│   ├── CourierExportBatch.php
│   ├── CourierExportRow.php
│   ├── CustomerIdentity.php
│   ├── FacebookAccount.php
│   ├── FacebookPage.php
│   ├── FacebookWebhookEvent.php
│   ├── Message.php
│   ├── Milestone.php
│   ├── PageAssignmentRule.php
│   └── Tag.php
└── Services/
    ├── AutoAssignmentService.php
    ├── BroadcastCampaignService.php
    ├── ConversationArchiveService.php
    ├── ConversationMergePreviewService.php
    ├── ConversationSlaService.php
    ├── CourierExportService.php
    ├── CustomerIdentityService.php
    ├── FacebookConnectorService.php
    ├── GamificationService.php
    ├── MetaConversationIngestor.php
    ├── PhoneDetectionService.php
    ├── ProductRecommendationService.php
    ├── RichMediaTemplateService.php
    ├── SentimentAnalysisService.php
    └── ShopReportsEnhancementService.php
```
