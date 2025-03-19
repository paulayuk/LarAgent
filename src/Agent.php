<?php

namespace LarAgent;

use Illuminate\Contracts\Auth\Authenticatable;
use LarAgent\Attributes\Tool as ToolAttribute;
use LarAgent\Core\Contracts\ChatHistory as ChatHistoryInterface;
use LarAgent\Core\Contracts\LlmDriver as LlmDriverInterface;
use LarAgent\Core\Contracts\Message as MessageInterface;
use LarAgent\Core\Contracts\Tool as ToolInterface;
use LarAgent\Core\DTO\AgentDTO;
use LarAgent\Core\Traits\Events;

/**
 * Class Agent
 * For creating Ai Agent by extending this class
 * Only class dependant on Laravel
 */
class Agent
{
    use Events;

    // Agent properties

    protected LarAgent $agent;

    protected LlmDriverInterface $llmDriver;

    protected ChatHistoryInterface $chatHistory;

    /** @var string|null */
    protected $message;

    /** @var string */
    protected $instructions;

    /** @var array */
    protected $responseSchema = [];

    /** @var array */
    protected $tools = [];

    /** @var string */
    protected $history;

    /** @var string */
    protected $driver;

    /** @var string */
    protected $provider = 'default';

    /** @var string */
    protected $providerName = '';

    /** @var bool */
    protected $developerRoleForInstructions = false;

    // Driver configs

    /** @var string */
    protected $model;

    /** @var int */
    protected $contextWindowSize;

    /**
     * Store message metadata with messages in chat history
     *
     * @var bool
     */
    protected $storeMeta;

    /** @var bool */
    protected $saveChatKeys;

    /**
     * Chat key associated with this agent
     *
     * @var string
     */
    protected $chatKey;

    /** @var int */
    protected $maxCompletionTokens;

    /** @var float */
    protected $temperature;

    /** @var int */
    protected $reinjectInstructionsPer;

    /** @var ?bool */
    protected $parallelToolCalls;

    /** @var string */
    protected $chatSessionId;

    // Misc
    private array $builtInHistories = [
        'in_memory' => \LarAgent\History\InMemoryChatHistory::class,
        'session' => \LarAgent\History\SessionChatHistory::class,
        'cache' => \LarAgent\History\CacheChatHistory::class,
        'file' => \LarAgent\History\FileChatHistory::class,
        'json' => \LarAgent\History\JsonChatHistory::class,
    ];

    /** @var array */
    protected $images = [];

    public function __construct($key)
    {
        $this->setupProviderData();
        $this->setChatSessionId($key);
        $this->setupChatHistory();
        $this->onInitialize();
    }

    public function __destruct()
    {
        $this->onTerminate();
    }

    // Public API

    /**
     * Create an agent instance for a specific user
     *
     * @param  Authenticatable  $user  The user to create agent for
     */
    public static function forUser(Authenticatable $user): static
    {
        $userId = $user->getAuthIdentifier();
        $instance = new static($userId);

        return $instance;
    }

    /**
     * Create an agent instance with a specific key
     *
     * @param  string  $key  The key to identify this agent instance
     */
    public static function for(string $key): static
    {
        $instance = new static($key);

        return $instance;
    }

    /**
     * Set the message for the agent to process
     *
     * @param  string  $message  The message to process
     */
    public function message(string $message): static
    {
        $this->message = $message;

        return $this;
    }

    /**
     * Process a message and get the agent's response
     *
     * @param  string|null  $message  Optional message to process
     * @return string|array The agent's response
     */
    public function respond(?string $message = null): string|array
    {
        if ($message) {
            $this->message($message);
        }

        $this->setupBeforeRespond();

        $this->onConversationStart();

        $message = Message::user($this->prompt($this->message));

        if (! empty($this->images)) {
            foreach ($this->images as $imageUrl) {
                $message = $message->withImage($imageUrl);
            }
        }

        $this->agent
            ->withInstructions($this->instructions(), $this->developerRoleForInstructions)
            ->withMessage($message)
            ->setTools($this->getTools());

        if ($this->structuredOutput()) {
            $this->agent->structured($this->structuredOutput());
        }

        $response = $this->agent->run();
        $this->onConversationEnd($response);

        return $response;
    }

    // Overridables

    /**
     * Get the instructions for the agent
     *
     * @return string The agent's instructions
     */
    public function instructions()
    {
        return $this->instructions;
    }

    /**
     * Get the model for the agent
     *
     * @return string The agent's model
     */
    public function model()
    {
        return $this->model;
    }

    /**
     * Process a message before sending to the agent
     *
     * @param  string  $message  The message to process
     * @return string The processed message
     */
    public function prompt(string $message)
    {
        return $message;
    }

    /**
     * Get the structured output schema if any
     *
     * @return array|null The response schema or null if none set
     */
    public function structuredOutput()
    {
        return $this->responseSchema ?? null;
    }

    /**
     * Create a new chat history instance
     *
     * @param  string  $sessionId  The session ID for the chat history
     * @return ChatHistoryInterface The created chat history instance
     */
    public function createChatHistory(string $sessionId)
    {
        $historyClass = $this->builtInHistories[$this->history] ?? $this->history;

        return new $historyClass($sessionId, [
            'context_window' => $this->contextWindowSize,
            'store_meta' => $this->storeMeta,
            'save_chat_keys' => $this->saveChatKeys,
        ]);
    }

    /**
     * Register additional tools for the agent
     *
     * Override this method in child classes to register custom tools.
     * Tools should be instances of LarAgent\Tool class.
     *
     * Example:
     * ```php
     * public function registerTools() {
     *     return [
     *         Tool::create("user_location", "Returns user's current location")
     *              ->setCallback(function () use ($user) {
     *                   return $user->location()->city;
     *              }),
     *         Tool::create("get_current_weather", "Returns the current weather in a given location")
     *              ->addProperty("location", "string", "The city and state, e.g. San Francisco, CA")
     *              ->setCallback("getWeather"),
     *     ];
     * }
     * ```
     *
     * @return array Array of Tool instances
     */
    public function registerTools()
    {
        return [];
    }

    // Public accessors / mutators

    public function getChatSessionId(): string
    {
        return $this->chatSessionId;
    }

    public function getProviderName(): string
    {
        return $this->providerName;
    }

    public function getTools(): array
    {
        // Get tools from $tools property (class names)
        $classTools = array_map(function ($tool) {
            return new $tool;
        }, $this->tools);

        // Get tools from registerTools method (instances)
        $registeredTools = $this->registerTools();

        $attributeTools = $this->buildToolsFromAttributeMethods();
        // print_r($attributeTools);

        // Merge both arrays
        return array_merge($classTools, $registeredTools, $attributeTools);
    }

    public function chatHistory(): ChatHistoryInterface
    {
        return $this->chatHistory;
    }

    public function setChatHistory(ChatHistoryInterface $chatHistory): static
    {
        $this->chatHistory = $chatHistory;

        return $this;
    }

    public function currentMessage(): ?string
    {
        return $this->message;
    }

    public function lastMessage(): ?MessageInterface
    {
        return $this->chatHistory->getLastMessage();
    }

    public function clear(): static
    {
        $this->onClear();
        $this->chatHistory->clear();
        $this->chatHistory->writeToMemory();

        return $this;
    }

    public function getChatKey(): string
    {
        return $this->chatKey;
    }

    /**
     * Get all chat keys associated with this agent class
     * 
     * @return array Array of chat keys filtered by agent class name
     */
    public function getChatKeys(): array
    {
        $keys = $this->chatHistory->loadKeysFromMemory();
        $agentClass = class_basename(static::class);

        return array_filter($keys, function ($key) use ($agentClass) {
            return str_starts_with($key, $agentClass . '_');
        });
    }

    public function withTool(ToolInterface $tool): static
    {
        $this->tools[] = $tool;
        $this->onToolChange($tool, true);

        return $this;
    }

    public function removeTool(string $name): static
    {
        foreach ($this->tools as $key => $tool) {
            if ($tool->getName() === $name) {
                unset($this->tools[$key]);
                $this->onToolChange($tool, false);
                break;
            }
        }

        return $this;
    }

    public function withImages(array $imageUrls): static
    {
        $this->images = $imageUrls;

        return $this;
    }

    public function temperature(float $temp): static
    {
        $this->temperature = $temp;

        return $this;
    }

    public function withModel(string $model): static
    {
        $this->model = $model;

        // Update chat session ID with new model
        $this->setChatSessionId($this->getChatKey());

        // Create new chat history with updated session ID
        $this->setupChatHistory();

        return $this;
    }

    public function addMessage(MessageInterface $message): static
    {
        $this->chatHistory()->addMessage($message);

        return $this;
    }

    /**
     * Convert Agent to DTO
     * // @todo mention DTO in the documentation as state for events
     */
    public function toDTO(): AgentDTO
    {
        $driverConfigs = array_filter([
            'model' => $this->model(),
            'contextWindowSize' => $this->contextWindowSize ?? null,
            'maxCompletionTokens' => $this->maxCompletionTokens ?? null,
            'temperature' => $this->temperature ?? null,
            'reinjectInstructionsPer' => $this->reinjectInstructionsPer ?? null,
            'parallelToolCalls' => $this->parallelToolCalls ?? null,
            'chatSessionId' => $this->chatSessionId,
        ], fn ($value) => ! is_null($value));

        return new AgentDTO(
            provider: $this->provider,
            providerName: $this->providerName,
            message: $this->message,
            tools: array_map(fn (ToolInterface $tool) => $tool->getName(), $this->getTools()),
            instructions: $this->instructions,
            responseSchema: $this->responseSchema,
            configuration: [
                'history' => $this->history,
                'model' => $this->llmDriver->getModel(),
                'driver' => $this->driver,
                ...$driverConfigs,
            ]
        );
    }

    // Helper methods

    protected function setChatSessionId(string $id): static
    {
        $this->chatKey = $id;
        $this->chatSessionId = $this->buildSessionId();

        return $this;
    }

    protected function buildSessionId()
    {
        return sprintf(
            '%s_%s_%s',
            class_basename(static::class),
            $this->model(),
            $this->getChatKey()
        );
    }

    protected function getProviderData(): ?array
    {
        return config("laragent.providers.{$this->provider}");
    }

    protected function setupDriverConfigs(array $providerData): void
    {
        if (! isset($this->model) && isset($providerData['model'])) {
            $this->model = $providerData['model'];
        }
        if (! isset($this->maxCompletionTokens) && isset($providerData['default_max_completion_tokens'])) {
            $this->maxCompletionTokens = $providerData['default_max_completion_tokens'];
        }
        if (! isset($this->contextWindowSize) && isset($providerData['default_context_window'])) {
            $this->contextWindowSize = $providerData['default_context_window'];
        }
        if (! isset($this->storeMeta) && isset($providerData['store_meta'])) {
            $this->storeMeta = $providerData['store_meta'];
        }
        if (! isset($this->saveChatKeys) && isset($providerData['save_chat_keys'])) {
            $this->saveChatKeys = $providerData['save_chat_keys'];
        }
        if (! isset($this->temperature) && isset($providerData['default_temperature'])) {
            $this->temperature = $providerData['default_temperature'];
        }
        if (! isset($this->parallelToolCalls) && isset($providerData['parallel_tool_calls'])) {
            $this->parallelToolCalls = $providerData['parallel_tool_calls'];
        }
    }

    protected function initDriver($settings): void
    {
        $this->llmDriver = new $this->driver($settings);
    }

    protected function setupProviderData(): void
    {
        $provider = $this->getProviderData();
        if (! isset($this->driver)) {
            $this->driver = $provider['driver'] ?? config('laragent.default_driver');
        }
        if (! isset($this->history)) {
            $this->history = $provider['chat_history'] ?? config('laragent.default_chat_history');
        }
        $this->providerName = $provider['name'] ?? '';
        $this->setupDriverConfigs($provider);

        $settings = array_merge($provider, $this->buildConfigsForLaragent());

        $this->initDriver($settings);
    }

    protected function setupAgent(): void
    {
        $config = $this->buildConfigsForLaragent();
        $this->agent = LarAgent::setup($this->llmDriver, $this->chatHistory, $config);
    }

    protected function buildConfigsForLaragent()
    {
        $config = [
            'model' => $this->model(),
        ];
        if (property_exists($this, 'maxCompletionTokens')) {
            $config['maxCompletionTokens'] = $this->maxCompletionTokens;
        }
        if (property_exists($this, 'temperature')) {
            $config['temperature'] = $this->temperature;
        }
        if (property_exists($this, 'parallelToolCalls')) {
            $config['parallelToolCalls'] = $this->parallelToolCalls;
        }

        return $config;
    }

    protected function registerEvents(): void
    {
        $instance = $this;

        $this->agent->beforeReinjectingInstructions(function ($agent, $chatHistory) use ($instance) {
            $returnValue = $instance->beforeReinjectingInstructions($chatHistory);

            // Explicitly check for false
            return $returnValue === false ? false : true;
        });

        $this->agent->beforeSend(function ($agent, $history, $message) use ($instance) {
            $returnValue = $instance->beforeSend($history, $message);

            // Explicitly check for false
            return $returnValue === false ? false : true;
        });

        $this->agent->afterSend(function ($agent, $history, $message) use ($instance) {
            $returnValue = $instance->afterSend($history, $message);

            // Explicitly check for false
            return $returnValue === false ? false : true;
        });

        $this->agent->beforeSaveHistory(function ($agent, $history) use ($instance) {
            $returnValue = $instance->beforeSaveHistory($history);

            // Explicitly check for false
            return $returnValue === false ? false : true;
        });

        $this->agent->beforeResponse(function ($agent, $history, $message) use ($instance) {
            $returnValue = $instance->beforeResponse($history, $message);

            // Explicitly check for false
            return $returnValue === false ? false : true;
        });

        $this->agent->afterResponse(function ($agent, $message) use ($instance) {
            $returnValue = $instance->afterResponse($message);

            // Explicitly check for false
            return $returnValue === false ? false : true;
        });

        $this->agent->beforeToolExecution(function ($agent, $tool) use ($instance) {
            $returnValue = $instance->beforeToolExecution($tool);

            // Explicitly check for false
            return $returnValue === false ? false : true;
        });

        $this->agent->afterToolExecution(function ($agent, $tool, &$result) use ($instance) {
            $returnValue = $instance->afterToolExecution($tool, $result);

            // Explicitly check for false
            return $returnValue === false ? false : true;
        });

        $this->agent->beforeStructuredOutput(function ($agent, &$response) use ($instance) {
            $returnValue = $instance->beforeStructuredOutput($response);

            // Explicitly check for false
            return $returnValue === false ? false : true;
        });
    }


    protected function setupBeforeRespond(): void
    {
        $this->setupAgent();
        $this->registerEvents();
    }

    protected function setupChatHistory(): void
    {
        $chatHistory = $this->createChatHistory($this->getChatSessionId());
        $this->setChatHistory($chatHistory);
    }


    /**
     * Builds tools from methods annotated with #[Tool] attribute
     * Example:
     * ```php
     * #[Tool("Get weather information")]
     * public function getWeather(string $location): array {
     *     return WeatherService::get($location);
     * }
     * ```
     */
    protected function buildToolsFromAttributeMethods(): array
    {
        $tools = [];
        $reflection = new \ReflectionClass($this);

        foreach ($reflection->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $attributes = $method->getAttributes(ToolAttribute::class);
            if (empty($attributes)) {
                continue;
            }

            foreach ($attributes as $attribute) {
                $toolAttribute = $attribute->newInstance();
                $tool = Tool::create(
                    $method->getName(),
                    $toolAttribute->description
                );

                // Add parameters as tool properties
                foreach ($method->getParameters() as $param) {
                    $type = $param->getType()?->getName() ?? 'string';
                    $AiType = $this->convertToOpenAIType($type);
                    $tool->addProperty(
                        $param->getName(),
                        isset($AiType['type']) ? $AiType['type'] : $AiType,
                        isset($toolAttribute->parameterDescriptions[$param->getName()]) ? $toolAttribute->parameterDescriptions[$param->getName()] : '',
                        isset($AiType['enum']) ? $AiType['enum'] : []
                    );
                    if (! $param->isOptional()) {
                        $tool->setRequired($param->getName());
                    }
                }

                $instance = $this;
                // Bind the method to the tool, handling both static and instance methods
                $tool->setCallback($method->isStatic()
                    ? [static::class, $method->getName()]
                    : [$this, $method->getName()]
                );
                $tools[] = $tool;
            }
        }

        return $tools;
    }

    protected function convertToOpenAIType($type)
    {

        if ($type instanceof \ReflectionEnum || (is_string($type) && enum_exists($type))) {
            $enumClass = is_string($type) ? $type : $type->getName();

            return [
                'type' => 'string',
                'enum' => [
                    'values' => array_map(fn ($case) => $case->value, $enumClass::cases()),
                    'enumClass' => $enumClass, // Store the enum class name for conversion
                ],
            ];
        }

        switch ($type) {
            case 'string':
                return 'string';
            case 'int':
                return 'integer';
            case 'float':
                return 'number';
            case 'bool':
                return 'boolean';
            case 'array':
                return 'array';
            case 'object':
                return 'object';
            default:
                return 'string';
        }
    }
}
