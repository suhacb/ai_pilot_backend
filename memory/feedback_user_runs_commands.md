---
name: feedback_user_runs_commands
description: User prefers to run shell commands themselves rather than having Claude execute them
metadata:
  type: feedback
---

User prefers to run shell/CLI commands themselves and will provide the output. Do not execute Bash commands that run the app, artisan, tests, or other project tools — instead, write the command and ask the user to run it (or suggest `! <command>`).

**Why:** User explicitly stopped a command execution and said "I will run it myself."

**How to apply:** After making code changes, write the verification command as a code block and ask the user to run it, rather than executing it via Bash.
