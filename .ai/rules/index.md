# Project Rules Index

Before planning or editing, find every row whose glob matches a file in scope and read every applicable rule file. If multiple rows match, all matching rules apply.

For any Phase 4+ capability work involving orchestration recommendations, knowledge intelligence, runtime recovery, Agent collaboration, voice, parallel execution, custom workflows, or automatic execution routing, also read the canonical governance contracts in `MASTER-PROMPT.md`, `AGENTS.md`, and the selected harness supplement such as `CLAUDE.md`, plus every path-specific rule matched below. Phase 4+ governance does not grant runtime authority by itself. Only separately approved implementation tasks may introduce capability code, and their Actions/Services remain subordinate to the AIOS-owned authority boundaries defined by those contracts.

| Applies to | Rule file |
| --- | --- |
| app/Actions/** | .ai/rules/actions.md |
| bootstrap/app.php | .ai/rules/bootstrap.md |
| app/Actions/*KnowledgeImprovement*.php | .ai/rules/knowledge-improvements.md |
| app/Actions/*GlobalKnowledgePattern*.php | .ai/rules/knowledge-improvements.md |
| app/Services/** | .ai/rules/services.md |
| app/Services/*KnowledgeImprovement*.php | .ai/rules/knowledge-improvements.md |
| app/Services/*KnowledgeSource*.php | .ai/rules/knowledge-improvements.md |
| app/KnowledgeImprovement*.php | .ai/rules/knowledge-improvements.md |
| app/Models/KnowledgeImprovement*.php | .ai/rules/knowledge-improvements.md |
| app/Models/KnowledgeSource*.php | .ai/rules/knowledge-improvements.md |
| app/Models/GlobalKnowledgePattern.php | .ai/rules/knowledge-improvements.md |
| app/Http/Controllers/**/*KnowledgeImprovement*.php | .ai/rules/knowledge-improvements.md |
| app/Http/Requests/**/*KnowledgeImprovement*.php | .ai/rules/knowledge-improvements.md |
| app/Http/Requests/**/*GlobalKnowledgePattern*.php | .ai/rules/knowledge-improvements.md |
| app/Console/Commands/*KnowledgeImprovement*.php | .ai/rules/knowledge-improvements.md |
| database/migrations/*knowledge_improvement*.php | .ai/rules/knowledge-improvements.md |
| database/migrations/*knowledge_source*.php | .ai/rules/knowledge-improvements.md |
| database/migrations/*global_knowledge_pattern*.php | .ai/rules/knowledge-improvements.md |
| database/factories/KnowledgeSource*.php | .ai/rules/knowledge-improvements.md |
| resources/js/**/knowledge-improvements/** | .ai/rules/knowledge-improvements.md |
| app/Ticket*.php | .ai/rules/tickets.md |
| app/Models/Ticket*.php | .ai/rules/tickets.md |
| app/Policies/Ticket*.php | .ai/rules/tickets.md |
| app/Http/Controllers/**/*Ticket*.php | .ai/rules/tickets.md |
| app/Http/Requests/**/*Ticket*.php | .ai/rules/tickets.md |
| app/Console/Commands/*Ticket*.php | .ai/rules/tickets.md |
| app/Console/Commands/RunAiosWorkers.php | .ai/rules/tickets.md |
| database/migrations/*ticket*.php | .ai/rules/tickets.md |
| resources/js/**/tickets/** | .ai/rules/tickets.md |
