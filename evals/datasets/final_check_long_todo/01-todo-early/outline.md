---
plan_id: 34
title: 'Symfony Messenger: Handling tasks in the background'
approved_at: '2026-10-19T03:43:18+00:00'
---
## Introduction: Why Messenger? (The Problem)

We start by discussing the frustration of messy, complicated YAML configuration for background tasks. We promise to show a simple, modern approach to handling message passing in Symfony Messenger.

## Understanding the Basics: Commands vs. Events

We define the core concepts: Commands are objects that *cause* a change (e.g., 'ProcessOrder'), while Events report that something *has already happened* (e.g., 'OrderProcessed').

## Core Components: The Messenger Toolkit

This section introduces the key building blocks: Senders (who send messages), Receivers (who get messages), Handlers (who process them), and Stamps (metadata about the message state).

## Step 1: Sending the First Message (The Synchronous Flow)

We begin the running example: Creating the `OrderPlaced` message and dispatching it immediately. We show how to use the `MessageBusInterface` to fire the message off.

## Step 2: Handling the Message (The Handler)

We write the first handler that listens for `OrderPlaced`. This handler is simple, using the `#[AsMessageHandler]` attribute, and performs the initial database logic.

## Step 3: Making it Asynchronous (The Transport)

To handle high traffic, we switch from immediate execution to background queues. We configure the `messenger.yaml` file to use a transport like AMQP, allowing workers to process the message later.

## Step 4: Building the Journey: Message Chaining

This is the core of the running example. After processing the order, the handler dispatches a *new* message (e.g., `SendInventoryUpdate`) to a different service or platform.

## Step 5: Improving Reliability (Retries and Failure)

We learn how to make our system robust. We configure `retry_strategy` for temporary failures and specify a `failure_transport` for messages that fail permanently.

## Advanced Patterns: Custom Serializers and Stamps

We dive into customization. We create a custom Serializer/Deserializer to handle complex object types, and we use custom Stamps to track specific message states (like 'SENT_TO_EMAIL').

## Advanced Topic: Building Custom Transports

For unique needs, we build our own transport. We implement the `SenderInterface` and `ReceiverInterface` to connect Symfony Messenger to systems that aren't standard (like an external API endpoint). 

## Summary: Your Complete Messaging Pipeline

We review the entire journey of the order: from initial command $ightarrow$ async processing $ightarrow$ multiple follow-up messages $ightarrow$ reliable delivery. You can now build complex, decoupled systems.
