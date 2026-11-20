// Provider presets for the AI assistant settings — mirror of
// AiClient::PRESETS (core/AiClient.php). `model` is the economical default;
// the actual model list comes from the provider (ai_models → GET /models).
export const AI_PROVIDERS = {
    glm: {
        baseUrl: 'https://api.z.ai/api/paas/v4',
        model: 'glm-4.5-flash',
        needsKey: true,
        keyUrl: 'https://z.ai/manage-apikey/apikey-list',
        baseHint: 'ai.setup.glmChinaHint',
    },
    glm_coding: {
        baseUrl: 'https://api.z.ai/api/coding/paas/v4',
        model: 'glm-4.6',
        needsKey: true,
        keyUrl: 'https://z.ai/manage-apikey/apikey-list',
        baseHint: 'ai.setup.glmCodingHint',
    },
    gemini: {
        baseUrl: 'https://generativelanguage.googleapis.com/v1beta/openai',
        model: 'gemini-flash-lite-latest',
        needsKey: true,
        keyUrl: 'https://aistudio.google.com/apikey',
    },
    openai: {
        baseUrl: 'https://api.openai.com/v1',
        model: 'gpt-5-nano',
        needsKey: true,
        keyUrl: 'https://platform.openai.com/api-keys',
    },
    anthropic: {
        baseUrl: 'https://api.anthropic.com/v1',
        model: 'claude-haiku-5-5',
        needsKey: true,
        keyUrl: 'https://console.anthropic.com/settings/keys',
    },
    deepseek: {
        baseUrl: 'https://api.deepseek.com/v1',
        model: 'deepseek-chat',
        needsKey: true,
        keyUrl: 'https://platform.deepseek.com/api_keys',
    },
    openrouter: {
        baseUrl: 'https://openrouter.ai/api/v1',
        model: 'openai/gpt-5-nano',
        needsKey: true,
        keyUrl: 'https://openrouter.ai/keys',
    },
    ollama: {
        baseUrl: 'http://127.0.0.1:11434/v1',
        model: 'qwen3',
        needsKey: false,
        keyUrl: 'https://ollama.com/download',
        baseHint: 'ai.setup.ollamaHint',
    },
    custom: {
        baseUrl: '',
        model: '',
        needsKey: false,
        keyUrl: null,
        baseHint: 'ai.setup.customHint',
    },
};
