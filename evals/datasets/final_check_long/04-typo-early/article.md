---
plan_id: 34
title: 'Mastering Symfony Messenger for robust service architecture'
subtitle: 'Implementing Commands, Events, Handlers, and resilient custom Transports in Symfony'
topics:
    - 'Software Architecture'
    - Microservices
    - Symfony
    - Messaging
    - 'Message Broker'
---
# Mastering Symfony Messenger for robust service architecture
## Implementing Commands, Events, Handlers, and resilient custom Transports in Symfony

Your app grows. The checkout saves the order, sends an email, updates the stock, and talks to the warehouse. The customer waits for all of it. Then one day the warehouse team says: "We are building our own app."

How do you send them your orders now?

If you tried Symfony Messenger years ago, you may remember a lot of YAML. Those days are over. One attribute registers a handler. The YAML that is left is short: where messages go, and how they travel.

Most Messenger tutorials stop at "run it in the background". The real questions start when a message must **leave your app**. What format does it travel in? How does the other app read it? What happens when it fails there?

This article is for Symfony developers who know services and controllers. In this guide, we'll build the order journey of a small shop. The customer places an order. The Shop asks a separate Warehouse app to reserve the stock through RabbitMQ. The Warehouse answers back. When the order comes back as **reserved**, the journey is complete, and so is this article.

All examples use PHP 8.4, Symfony 8.1 and RabbitMQ 4. The full project (both apps and a `compose.yaml`) is on GitHub: **[repo link]**.

## Meet the Shop and the Warehouse

Our shop sells mugs. A customer orders 2 red mugs. This is order **#42**, and we will follow it from start to end.

Two teams, two apps:

- **Shop**: owns customers and orders.
- **Warehouse**: owns the shelves and the stock.

They are separate codebases. They share no PHP code, not even one class. The only thing that goes between them is **messages**.

## Commands vs. Events

Before we write code, one idea matters a lot. Messages come in two kinds.

- A **command** asks for a change: "Please reserve 2 red mugs." It has one receiver.
- An **event** reports a fact: "Order #42 was placed." Anyone can listen to it.

A simple way to remember it:

- **Command:** *I want* you to do X.
- **Event:** X *has happened*.

Our journey uses both. `OrderPlaced` is an event inside the Shop. `ReserveStock` is a command from the Shop to the Warehouse. `StockReserved` is the Warehouse's event back to the Shop.

## The Messenger Toolkit

Messenger borrows its words from the post office. That makes it easy to remember:

- **Message:** the letter. A plain PHP object with your data, like `OrderPlaced`.
- **Bus:** the front desk. You give it a message with `dispatch()`.
- **Envelope:** Messenger wraps every message in one.
- **Stamps:** stickers on the envelope. Extra information *about* the message, not the message itself.
- **Transport:** the delivery service. It has a **sender** (puts letters in a queue) and a **receiver** (takes them out).
- **Handler:** the person who opens the letter and does the work.
- **Worker:** a long-running command (`messenger:consume`) that reads from a transport and calls the handlers.

Keep the post office in mind. We will need it again when RabbitMQ arrives.

## Place the Order using the Message Bus

Let's start our journey. The customer clicks "Buy". We create an `OrderPlaced` message.

```php
namespace App\Message;

final readonly class OrderPlaced
{
    public function __construct(
        public int $orderId,
        public string $sku,
        public int $quantity,
    ) {
    }
}
```

No getters, no setters. `readonly` public properties are enough. A message is data, and it should never change after you create it.

Now the checkout dispatches it. In the repo the checkout is a console command, so you can run it without a browser:

```php
namespace App\Command;

#[AsCommand('app:place-order', 'Checkout: the customer places an order')]
final readonly class PlaceOrderCommand
{
    public function __construct(private MessageBusInterface $bus)
    {
    }

    public function __invoke(SymfonyStyle $io, #[Argument] int $orderId, #[Argument] string $sku, #[Argument] int $quantity): int
    {
        // 1. Save the order (skipped in this demo)
        // 2. Tell the rest of the system it happened
        $this->bus->dispatch(new OrderPlaced($orderId, $sku, $qauntity));

        $io->success("Order #$orderId placed.");

        return Command::SUCCESS;
    }
}
```

The checkout does not know who cares about the order. It only says: "This happened."

## Handle the Order using a Handler

Someone must react to `OrderPlaced`. That is a handler.

```php
namespace App\MessageHandler;

#[AsMessageHandler]
final readonly class OrderPlacedHandler
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function __invoke(OrderPlaced $event): void
    {
        $this->logger->info('Order #{id} saved.', ['id' => $event->orderId]);
    }
}
```

That's it! No YAML, no tags. `#[AsMessageHandler]` registers the class, and the type of the `__invoke()` argument tells Messenger which message it handles.

Right now the handler runs **inside** the checkout request. If it is slow, the customer waits. Let's fix that.

## Move the Work to the Background using RabbitMQ

**RabbitMQ** is a message broker. It keeps messages safe until a worker takes them. It is open source and free, and the official Docker image comes with a web dashboard.

### Step 1. Start RabbitMQ

```yaml
# compose.yaml
services:
  rabbitmq:
    image: rabbitmq:4-management
    ports:
      - "5672:5672"   # AMQP, the apps talk here
      - "15672:15672" # Management UI, you look here (guest / guest)
```

Run `docker compose up -d` and open `http://localhost:15672`.

### Step 2. Install the AMQP transport

```bash
composer require symfony/amqp-messenger
```

Your PHP also needs the `amqp` extension (`pecl install amqp`). Many PHP Docker images already ship it. The repo uses one of them.

Note: if you use Laravel, this is the same idea as a queue connection. The broker does not care which framework sends the message.

### Step 3. Route the message to a transport

```yaml
# shop: config/packages/messenger.yaml
framework:
    messenger:
        transports:
            # Inside the Shop: background work only the Shop cares about
            async:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                options:
                    exchange: { name: shop_internal }
                    queues: { shop_internal: [] }

        routing:
            App\Message\OrderPlaced: async
```

```dotenv
MESSENGER_TRANSPORT_DSN=amqp://guest:guest@localhost:5672/%2f
```

Back to the post office. RabbitMQ has two parts you need to know:

- **Exchange:** the sorting room. Senders never write to a queue directly. They give the letter to an exchange.
- **Queue:** a mailbox. A worker reads from it.

A **binding** is the rule that connects them: "letters with this address go to this mailbox". The address is called the **routing key**.

Let's run it:

```bash
bin/console messenger:setup-transports   # creates the exchange and the queue
bin/console app:place-order 42 SKU-RED-MUG 2
bin/console messenger:consume async -vv
```

The checkout returns right away. The worker picks the message up a moment later:

```text
INFO [messenger] Received message App\Message\OrderPlaced
INFO [app] Order #42 saved.
INFO [messenger] App\Message\OrderPlaced was handled successfully (acknowledging to transport).
```

The customer no longer waits for the handler. But the order is still inside the Shop. The Warehouse doesn't know about it yet.

## Ask the Warehouse for Stock using Message Chaining

When the order is saved, the Shop must ask the Warehouse to reserve the mugs. That is a new message, sent from inside the handler. This is **message chaining**: one message leads to the next.

```php
namespace App\Message;

final readonly class ReserveStock
{
    public function __construct(
        public int $orderId,
        public string $sku,
        public int $quantity,
    ) {
    }
}
```

The handler now gets the bus and dispatches the command:

```php
public function __construct(
    private MessageBusInterface $bus,
    private LoggerInterface $logger,
) {
}

public function __invoke(OrderPlaced $event): void
{
    $this->logger->info('Order #{id} saved. Asking the Warehouse for stock.', ['id' => $event->orderId]);

    $this->bus->dispatch(new ReserveStock($event->orderId, $event->sku, $event->quantity));
}
```

`ReserveStock` must not stay in `shop_internal`. It goes to a second transport, the one both apps share:

```yaml
# shop: config/packages/messenger.yaml
        transports:
            # ... async ...

            # Between apps: the shared "orders" exchange
            orders:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                options:
                    exchange:
                        name: orders
                        type: topic
                        default_publish_routing_key: stock.reserve
                    queues:
                        shop: { binding_keys: [stock.reserved] }

        routing:
            App\Message\OrderPlaced: async
            App\Message\ReserveStock: orders
```

A few things to notice:

- **One exchange, two mailboxes.** The `orders` exchange is a `topic` exchange. It reads the routing key and copies the letter to every queue whose binding matches.
- **The Shop sends with the routing key `stock.reserve`.** The Warehouse's queue will listen to that key.
- **The Shop's own queue listens to `stock.reserved`.** That is where the Warehouse's answer will arrive later.

Note: You might be thinking, "Why doesn't the Shop create the Warehouse's queue too?" Good question! The queue belongs to the app that reads it, so the Warehouse creates its own. There is one catch: an exchange **drops** a message when no queue matches it. So run `messenger:setup-transports` in the Warehouse before the Shop sends its first order. The mailbox must exist before the letter arrives.

The command is now in the Warehouse's mailbox. Let's walk over to the other app and open it.

## Meet the Warehouse: Why the Default Serializer Breaks

The Warehouse is a fresh Symfony app. It has its own `ReserveStock` class, in its own namespace:

```php
namespace App\Stock;

final readonly class ReserveStock
{
    public function __construct(
        public int $orderId,
        public string $sku,
        public int $quantity,
    ) {
    }
}
```

It points a transport at the same exchange and binds its own queue to `stock.reserve`:

```yaml
# warehouse: config/packages/messenger.yaml
framework:
    messenger:
        transports:
            orders:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                options:
                    exchange:
                        name: orders
                        type: topic
                        default_publish_routing_key: stock.reserved
                    queues:
                        warehouse: { binding_keys: [stock.reserve] }
```

We start the worker with `bin/console messenger:consume orders -vv` and it fails:

```text
WARNING [messenger] Error thrown while handling message
Symfony\Component\Messenger\Exception\MessageDecodingFailedException.
Error: "Could not decode Envelope: Message class "App\Message\ReserveStock" not found during decoding."
```

Why? Let's look at what is really inside the message. Open the `warehouse` queue in the RabbitMQ dashboard and click "Get messages". This is the body (shortened):

```text
❌ O:36:"Symfony\Component\Messenger\Envelope":2:{ ... O:24:"App\Message\ReserveStock":3:{ ... } }
```

By default, Messenger uses the **PhpSerializer**. It turns the whole envelope into a string with PHP's own `serialize()`, class names included. Inside one app that is fine, because the classes are always there. Between two apps, it is a private language. The Warehouse doesn't have `App\Message\ReserveStock`, and it shouldn't need it.

What we want on the wire is this:

```text
✅ headers: type = stock.reserve
   body:    {"orderId":42,"sku":"SKU-RED-MUG","quantity":2}
```

A name both teams agree on, and plain JSON. Any app can read that.

## Speak a Common Language using a Custom Serializer

A **serializer** turns an envelope into a body and headers (`encode()`), and back (`decode()`). We write our own. Its core is a small **contract**: one public name for each message. Each app maps those names to its own classes.

```php
namespace App\Messenger;

final class OrderMessageSerializer implements SerializerInterface
{
    // The contract: one public name per message. Every app uses the same names.
    private const TYPES = [
        'stock.reserve' => ReserveStock::class,
        'stock.reserved' => StockReserved::class,
    ];

    public function encode(Envelope $envelope): array
    {
        $message = $envelope->getMessage();
        $headers = [
            'type' => array_search($message::class, self::TYPES, true),
            'Content-Type' => 'application/json',
        ];

        return [
            'body' => json_encode($message, \JSON_THROW_ON_ERROR),
            'headers' => $headers,
        ];
    }

    public function decode(array $encodedEnvelope): Envelope
    {
        $headers = $encodedEnvelope['headers'] ?? [];
        $class = self::TYPES[$headers['type'] ?? ''] ?? throw new MessageDecodingFailedException('Unknown message type.');

        try {
            $message = new $class(...json_decode($encodedEnvelope['body'], true, flags: \JSON_THROW_ON_ERROR));
        } catch (\JsonException|\Error $e) {
            throw new MessageDecodingFailedException($e->getMessage(), previous: $e);
        }

        return new Envelope($message);
    }
}
```

A few things to notice:

- **`json_encode($message)`** works because the message has only public properties.
- **`new $class(...$data)`** uses the JSON keys as named arguments. `{"orderId":42}` becomes `orderId: 42`. A missing or wrong field throws an `\Error`, and we turn it into a `MessageDecodingFailedException`, so Messenger knows the message can't be read.
- **The Warehouse has the same file** with one difference: its `use` lines point to `App\Stock\...` instead of `App\Message\...`. Same names on the wire, different classes in the code.

Now tell both apps to use it. One line on the `orders` transport, in each app:

```yaml
            orders:
                dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
                serializer: App\Messenger\OrderMessageSerializer
```

The internal `async` transport keeps the default serializer. It never leaves the Shop, so there is no reason to change it.

To see the wire, I bound one extra queue to the `orders` exchange with the key `#` (it matches everything). Here is order #42 as it really travels:

```text
routing key: stock.reserve
content_type: application/json
headers: {'type': 'stock.reserve'}
body: {"orderId":42,"sku":"SKU-RED-MUG","quantity":2}
```

That's it! Plain JSON and one header. A Node or Go service could read this message too.

But the letter has no history now. The Warehouse can't tell where the order is in its journey, or which request it belongs to. Let's put a sticker on the envelope.

## Carry the Order State using a Custom Stamp

We want two things to travel with every message: the **state** of the order (`placed`, `reserved`, …) and a **trace id**, the same id in both apps. With it, you can follow one order through both apps' logs.

That information is *about* the message, not the message itself. That is what a stamp is for.

```php
namespace App\Messenger;

final readonly class OrderStateStamp implements StampInterface
{
    public function __construct(
        public string $state,   // where the order is in its journey: placed, reserved, ...
        public string $traceId, // the same id in every app, so you can follow one order
    ) {
    }
}
```

### Step 1. The Shop adds the stamp when it sends

```php
public function __invoke(OrderPlaced $event): void
{
    $traceId = bin2hex(random_bytes(4));
    $this->logger->info('Order #{id} saved. Asking the Warehouse for stock. Trace {trace}.', ['id' => $event->orderId, 'trace' => $traceId]);

    $this->bus->dispatch(
        new ReserveStock($event->orderId, $event->sku, $event->quantity),
        [new OrderStateStamp('placed', $traceId)],
    );
}
```

The second argument of `dispatch()` is a list of stamps.

### Step 2. The serializer carries it across

Stamps don't cross the border on their own. The default PhpSerializer hid them inside the PHP string. Our serializer must write them as headers, and read them back on the other side.

```php
// in encode()
if ($state = $envelope->last(OrderStateStamp::class)) {
    $headers['X-Order-State'] = $state->state;
    $headers['X-Trace-Id'] = $state->traceId;
}

// in decode()
$stamps = [];

if (isset($headers['X-Order-State'])) {
    $state = new OrderStateStamp($headers['X-Order-State'], $headers['X-Trace-Id']);
    $stamps[] = $state;
    $stamps[] = new HandlerArgumentsStamp([$state]); // pass the stamp to the handler
}

return new Envelope($message, $stamps);
```

### Step 3. The Warehouse handler reads it

A handler normally gets only the message, never the envelope. The built-in **`HandlerArgumentsStamp`** changes that. Every value in it is passed to the handler as an extra argument. So the Warehouse handler simply asks for the stamp:

```php
namespace App\MessageHandler;

#[AsMessageHandler]
final readonly class ReserveStockHandler
{
    public function __invoke(ReserveStock $command, OrderStateStamp $state): void
    {
        // Reserve the items in the warehouse database (skipped in this demo)
        $this->logger->info('Order #{id} arrived as "{state}". Reserved {qty} x {sku}. Trace {trace}.', [...]);
    }
}
```

Note: You might be thinking, "Why not put the state and trace id in the message itself?" Good question! The message is the business request: reserve 2 red mugs for order #42. The stamp is about the journey. Keeping them apart keeps your contract clean. Tomorrow you can add a third app that ignores the headers and reads only the body.

Here is the wire now:

```text
headers: {'X-Order-State': 'placed', 'X-Trace-Id': 'ee1b9ce1', 'type': 'stock.reserve'}
body: {"orderId":42,"sku":"SKU-RED-MUG","quantity":2}
```

The Warehouse has reserved the mugs. But the Shop is still waiting. Nobody told it.

## Hear Back from the Warehouse using the Same Contract

The answer is an event: `StockReserved`. The Warehouse has its own class for it, `App\Stock\StockReserved`. The Shop has its own, `App\Message\StockReserved`. Same three fields.

The Warehouse handler dispatches it, with the stamp moved to the next state and the **same** trace id:

```php
public function __invoke(ReserveStock $command, OrderStateStamp $state): void
{
    // ... reserve the items ...

    $this->bus->dispatch(
        new StockReserved($command->orderId, $command->sku, $command->quantity),
        [new OrderStateStamp('reserved', $state->traceId)],
    );
}
```

```yaml
# warehouse: config/packages/messenger.yaml
        routing:
            App\Stock\StockReserved: orders
```

The Warehouse's `orders` transport sends with the routing key `stock.reserved`. Remember the binding we gave the Shop's queue back in the chaining step? That is where the answer lands.

The Shop needs one more handler:

```php
namespace App\MessageHandler;

#[AsMessageHandler]
final readonly class StockReservedHandler
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    public function __invoke(StockReserved $event, OrderStateStamp $state): void
    {
        // Update the order in the database (skipped in this demo)
        $this->logger->info('Order #{id} is now {state}. Trace {trace}.', ['id' => $event->orderId, 'state' => $state->state, 'trace' => $state->traceId]);
    }
}
```

Now run the whole journey. Three workers, two apps:

```bash
shop$      bin/console app:place-order 42 SKU-RED-MUG 2
shop$      bin/console messenger:consume async -vv
warehouse$ bin/console messenger:consume orders -vv
shop$      bin/console messenger:consume orders -vv
```

```text
[shop]      Order #42 saved. Asking the Warehouse for stock. Trace ee1b9ce1.
[warehouse] Order #42 arrived as "placed". Reserved 2 x SKU-RED-MUG. Trace ee1b9ce1.
[shop]      Order #42 is now reserved. Trace ee1b9ce1.
```

That's it! One order, two apps, one trace id. Search your logs for `ee1b9ce1` and you see the whole story.

Our happy path works. But real warehouses have bad days.

## Survive Failures using Retries and a Failure Transport

Networks drop. Databases restart. A good message system expects that. Messenger gives us two tools: **retries** for short problems, and a **failure transport** for messages that keep failing.

```yaml
# warehouse: config/packages/messenger.yaml
framework:
    messenger:
        failure_transport: failed

        transports:
            orders:
                # ... dsn, serializer, options ...
                retry_strategy:
                    max_retries: 3
                    delay: 1000    # 1 second
                    multiplier: 2  # 1s, 2s, 4s

            failed: 'doctrine://default?queue_name=failed'
```

A few things to notice:

- **`retry_strategy` belongs to a transport**, not to `messenger:` itself. Each transport can retry in its own way.
- **RabbitMQ does the waiting.** Messenger creates small delay queues on its own. The worker is free to handle other messages meanwhile.
- **The failure transport uses Doctrine**, not RabbitMQ. Messenger can't list the messages in an AMQP queue, so `messenger:failed:show` only works with a transport that can list them, like Doctrine.

Let's break it on purpose. In the demo, the Warehouse handler throws for the SKU `SKU-BROKEN`, like a database that is down:

```text
WARNING  Sending for retry #1 using 978 ms delay. Error: "Stock database is not reachable."
WARNING  Sending for retry #2 using 1871 ms delay. Error: "Stock database is not reachable."
WARNING  Sending for retry #3 using 3788 ms delay. Error: "Stock database is not reachable."
CRITICAL Removing from transport after 3 retries. Error: "Stock database is not reachable."
```

The delays are not exactly 1, 2 and 4 seconds. Messenger adds a small random **jitter** (10% by default), so a hundred failed messages don't all come back at the same moment.

The message is not lost. It waits in the failure transport:

```text
$ bin/console messenger:failed:show
  Id   Class                    Failed at             Error
  1    App\Stock\ReserveStock   2026-10-19 05:59:07   Stock database is not reachable.
```

When the database is back, `bin/console messenger:failed:retry` sends it through the handler again.

### The trap: a custom serializer can make retries endless

This one is easy to miss. Messenger counts retries with a stamp: the **`RedeliveryStamp`**. A retried message goes back into RabbitMQ, so it passes through **our** serializer again. Our serializer only knows about `OrderStateStamp`. The retry count is lost on the way, and every attempt looks like the first one:

```text
❌ Sending for retry #1 ...
   Sending for retry #1 ...
   Sending for retry #1 ...   (forever)
```

The fix is two small blocks in the serializer:

```php
// in encode()
if ($redelivery = $envelope->last(RedeliveryStamp::class)) {
    $headers['X-Retry-Count'] = $redelivery->getRetryCount();
}

// in decode()
if (isset($headers['X-Retry-Count'])) {
    $stamps[] = new RedeliveryStamp((int) $headers['X-Retry-Count']);
}
```

✅ With it, the count goes 1, 2, 3, and the message moves to the failure transport, as we saw above.

### One more rule: handle a message twice, safely

RabbitMQ delivers every message **at least once**. If a worker crashes after the work but before it tells RabbitMQ "done", the message comes again. So make your handlers safe to run twice. For example, the Warehouse first checks if order #42 already has a reservation, and skips the work if it does. *(This one is from my own practice.)*

## What About Custom Transports?

Sometimes the other side is not a message broker at all. It is a REST API, a CSV file, or an email inbox. Then you can write your own transport: a class that implements `SenderInterface` and `ReceiverInterface`, plus a `TransportFactory` that creates it. For our journey RabbitMQ does the job, so we don't need one. The Symfony docs have a full example: [How to Create Your own Messenger Transport](https://symfony.com/doc/current/messenger/custom-transport.html).

## Let's Stop and Look at What We Built

Look at the whole picture for a second:

```text
 SHOP                              RabbitMQ                              WAREHOUSE

 app:place-order
   └─ OrderPlaced ──────────► [shop_internal]
 OrderPlacedHandler ◄─────────────┘
   └─ ReserveStock ──► (orders) ──stock.reserve──► [warehouse] ──► ReserveStockHandler
      state: placed                                                    └─ StockReserved
                                                                          state: reserved
 StockReservedHandler ◄── [shop] ◄──stock.reserved── (orders) ◄─────────────┘

 (name) = exchange    [name] = queue
```

The journey of order #42:

1. The checkout dispatches `OrderPlaced` and returns at once.
2. A Shop worker handles it and chains a `ReserveStock` command, stamped `placed`.
3. Our serializer turns it into JSON and headers. RabbitMQ routes it by the key `stock.reserve`.
4. The Warehouse decodes it into **its own** class, reserves the mugs, and answers with `StockReserved`, stamped `reserved`.
5. The Shop decodes the answer and marks the order as reserved. Same trace id from start to end.
6. If the Warehouse fails, it retries 3 times, then parks the message in the failure transport.

Look at the contract one more time. It is not a PHP class. It is a list of names and fields, plus a few headers. That is the real reason the two apps can live apart.

## Golden Rules for Messages Between Apps

1. **Send JSON, not PHP objects.** The default serializer is for messages that stay inside one app.
2. **Name messages by contract, not by class.** `stock.reserve` is forever. `App\Message\ReserveStock` is one team's detail.
3. **The reader owns its queue.** Set it up before anyone sends to it.
4. **Carry the retry count in your serializer.** Otherwise retries never end.
5. **Make handlers safe to run twice.** *(This one is from my own practice.)*

## Conclusion

We started with one app where the customer waited for everything. We end with two apps that talk without sharing a single class:

- **A background worker** replaced the customer's waiting time.
- **A topic exchange with routing keys** replaced direct calls between apps.
- **A custom serializer and a JSON contract** replaced PHP's private `serialize()` format.
- **A custom stamp** replaced guesswork about where an order is and which request it belongs to.
- **Retries and a failure transport** replaced lost orders on a bad day.

Here is the proof that the apps are really separate. Search the Warehouse code for the Shop's message namespace:

```bash
$ grep -rn 'App\\Message\\' warehouse/src
$
```

Nothing. The Warehouse never saw a Shop class, and it still reserved our 2 red mugs.

The warehouse team built their own app, and your orders still reach it. You can do this now.

The full project, with both apps and a `compose.yaml`, is on GitHub: **[repo link]**.

### Further Reading

- [Symfony Messenger documentation](https://symfony.com/doc/current/messenger.html)
- [How to Create Your own Messenger Transport](https://symfony.com/doc/current/messenger/custom-transport.html)
- [Matthias Noback on command buses, commands and events](https://matthiasnoback.nl/tags/command-bus/)
- [RabbitMQ tutorials: routing and topics](https://www.rabbitmq.com/tutorials)
