<?php

declare(strict_types=1);

return [

    'disclaimer' => 'AI can make mistakes. Check important information before using it.',
    'result' => 'Result',
    'empty_result' => 'No answer yet.',
    'copied' => 'Copied',

    'features' => [
        'actions' => 'AI actions',
        'assist' => 'Writing assistant',
        'filters' => 'Table filters',
        'copilot' => 'Copilot',
        'mcp' => 'MCP server',
    ],

    'actions' => [
        'accept' => 'Accept',
        'apply' => 'Apply',
        'close' => 'Close',
        'discard' => 'Discard',
    ],

    'prebuilt' => [
        'summarize' => 'Summarize',
        'translate' => 'Translate',
        'extract' => 'Fill with AI',
        'classify' => 'Classify',
    ],

    'translate' => [
        'to' => 'Translate to',
    ],

    'extract' => [
        'text' => 'Text to extract from',
        'text_help' => 'Paste an email, a document or notes, then click outside the box. You review the values before they fill the form.',
        'file' => 'Or a file (PDF, image or text)',
    ],

    'classify' => [
        'value' => 'Suggested value',
        'reason' => 'Why',
    ],

    'assist' => [
        'label' => 'Write with AI',
        'heading' => 'Writing assistant',
        'accept' => 'Use suggestion',
        'instructions' => 'Instructions',
        'instructions_placeholder' => 'What should the text say? (needed for Generate, optional otherwise)',
        'suggestion' => 'Suggestion',
        'suggestion_for' => 'Suggestion: :task',
        'suggestion_help' => 'Edit it if you like. The field only changes when you click "Use suggestion".',
        'needs_instructions' => 'Write some instructions first.',
        'needs_text' => 'The field is empty: write something or use Generate.',
        'tasks' => [
            'generate' => 'Generate',
            'shorter' => 'Shorter',
            'longer' => 'Longer',
            'friendlier' => 'Friendlier',
            'formal' => 'More formal',
            'grammar' => 'Fix grammar',
            'translate' => 'Translate',
            'continue' => 'Continue writing',
        ],
    ],

    'filters' => [
        'label' => 'Ask AI',
        'heading' => 'Filter with AI',
        'description' => 'Describe what you want to see. The AI only uses this table\'s filters, and you can change them afterwards.',
        'placeholder' => 'e.g. paid orders over 100 from last week, newest first',
        'apply' => 'Apply filters',
        'applied' => 'Filters applied',
        'unsupported' => 'That can\'t be expressed with this table\'s filters.',
        'rejected' => 'The AI suggested filters this table doesn\'t have, so nothing was applied.',
        'errors' => [
            'not_searchable' => 'This table has no search.',
            'unknown_column' => 'Unknown sort column ":name".',
            'unknown_filter' => 'Unknown filter ":name".',
            'unknown_field' => 'Unknown filter field ":name".',
            'unknown_constraint' => 'Unknown rule ":name".',
            'unknown_operator' => 'The rule ":constraint" has no operator ":name".',
            'invalid_value' => 'Invalid value for ":name".',
            'no_query_builder' => 'This table has no query builder for rules.',
        ],
    ],

    'copilot' => [
        'activity' => [
            'searched' => 'Searched “:query”',
            'listed' => 'Listed records',
            'read' => 'Read :record',
        ],
        'new_record' => 'New :label',
        'no_answer' => 'I have no answer for that.',
        'action_done' => 'Done: :action on :record.',
        'action_unavailable' => 'That action is no longer available for you on this record.',
        'action_invalid' => 'The action needs other values:',
        'action_halted' => 'The action stopped before finishing.',
    ],

    'setup' => [
        'not_configured' => 'AI isn\'t set up yet.',
        'hint' => 'Run :command to add a provider key.',
        'unavailable' => 'AI is not available right now.',
    ],

    // Sample answers of AiPlugin::demoResponses() (no provider key).
    'demo' => [
        'label' => 'Sample answer — AI isn\'t configured',
        'description' => 'These answers are generated locally as examples, not by an AI model.',
        'translation' => '[Sample translation · :language]',
        'answer' => 'This is a sample answer. With a provider key, the AI would answer: “:request”',
        'field' => 'Sample :label',
        'field_text' => 'Sample text for: :request',
        'generated' => 'A first draft about “:instructions”. Set up AI for real suggestions.',
        'longer' => 'It covers the key details, and more can be added as needed.',
        'friendlier_open' => 'Hi there!',
        'friendlier_close' => 'Thanks so much!',
        'formal_open' => 'Dear Sir or Madam,',
        'formal_close' => 'Kind regards.',
        'continue' => 'More details will follow.',
        'classify_match' => 'Sample answer: the text mentions “:words”.',
        'classify_default' => 'Sample answer: the text names none of the options, so the first one is suggested.',
        'filters_applied' => 'Showing: :filters',
        'filters_help' => 'Sample filters understand simple phrases. Try e.g. “:example”.',
        'copilot' => [
            'found' => '{1} I found one record for “:query”:|[2,*] I found :count records for “:query”:',
            'latest' => 'The latest :label:',
            'none' => 'Nothing matches “:query”.',
            'opened' => 'Here is the link.',
            'not_found' => 'I couldn\'t find “:query”.',
            'proposed' => 'Review the card below and confirm or cancel it. Nothing has run yet.',
            'no_action' => 'I couldn\'t find the action “:action” on :record. Available: :actions.',
            'reason' => 'You asked to run :action on :record.',
            'records' => 'records',
            'help' => "Without AI I understand simple commands:\n- search :plural for a word, e.g. a name or number\n- open :plural, or open a record by its name\n- run an action on a record, e.g. run edit on a name",
        ],
    ],

    'errors' => [
        'title' => 'AI request failed',
        'unavailable' => 'AI is not available here.',
        'forbidden' => 'You are not allowed to use AI here.',
        'failed' => 'The AI service did not answer. Try again in a moment.',
        'invalid_answer' => 'The AI answer could not be read. Try again.',
        'rate_limited' => 'Too many AI requests. Try again in :seconds seconds.',
    ],

    'usage' => [
        'label' => 'AI call',
        'plural' => 'AI usage',
        'navigation' => 'AI usage',
        'empty' => 'No AI calls yet',
        'columns' => [
            'date' => 'Date',
            'feature' => 'Feature',
            'context' => 'Context',
            'user' => 'User',
            'model' => 'Model',
            'input_tokens' => 'Input tokens',
            'output_tokens' => 'Output tokens',
            'cost' => 'Cost (est.)',
            'duration' => 'Duration',
            'status' => 'Status',
        ],
        'filters' => [
            'from' => 'From',
            'until' => 'Until',
        ],
        'status' => [
            'success' => 'Success',
            'failed' => 'Failed',
        ],
        'stats' => [
            'calls' => 'Calls',
            'failed' => ':count failed',
            'tokens' => 'Tokens',
            'tokens_split' => ':input in · :output out',
            'cost' => 'Estimated cost',
            'no_pricing' => 'Add prices with AiPlugin::pricing()',
            'duration' => 'Average duration',
        ],
    ],

    // Shared with the renderers as `ai.*` (see AiPlugin::register()).
    'ui' => [
        'open' => 'Open AI copilot',
        'title' => 'Copilot',
        'new' => 'New conversation',
        'history' => 'Conversations',
        'back' => 'Back to the conversation',
        'close' => 'Close copilot',
        'disclaimer' => 'AI can make mistakes. Check important information.',
        'placeholder' => 'Ask about your data…',
        'send' => 'Send',
        'thinking' => 'Thinking…',
        'empty_title' => 'How can I help?',
        'empty_description' => 'Ask me to find or explain records, open pages or run actions. I always ask before changing anything.',
        'no_conversations' => 'No conversations yet.',
        'delete' => 'Delete conversation',
        'loading' => 'Loading',
        'you' => 'You',
        'assistant' => 'Copilot',
        'proposed_action' => 'Proposed action',
        'confirm' => 'Confirm',
        'cancel' => 'Cancel',
        'pending' => 'Waiting for your confirmation',
        'confirmed' => 'Done',
        'rejected' => 'Cancelled',
        'failed' => 'Failed',
        'open_link' => 'Open',
        'tokens' => ':count tokens',
        'cost' => '≈ :cost',
        'error' => 'Something went wrong. Try again.',
        'shortcut' => 'Shortcut: :keys',
        'messages' => 'Messages',
        'sample' => 'Sample answer — AI isn\'t configured',
        'stream' => [
            'writing' => 'Writing…',
            'stop' => 'Stop',
            'stop_label' => 'Stop writing the answer',
            'stopped' => 'Stopped.',
            'failed' => 'The answer could not be written. Try again.',
            'done' => 'Answer complete.',
        ],
    ],

];
