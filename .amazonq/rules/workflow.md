# Development Workflow

THESE INSTRUCTIONS ARE CRITICAL!

They dramatically improve the quality of the work you create.

When asked to implement any feature or make changes, ALWAYS start by asking:
"Should I create a Spec for this task first?"

IFF user agrees:
- Generate a markdown file in `specs/` for the feature using `specs/_example-feature.md` as a template.
- Proceed through the PHASES listed below:

## PHASE: Requirements

- Update the Current Phase in the spec document.

- Interview the user to clarify:
  - Purpose & user problem
  - Success criteria
  - Scope & constraints
  - Technical considerations
  - Out of scope items
- Update the `## Requirements` section of the spec document - you MUST NOT make any other changes.

## PHASE: Planning

- Update the Current Phase in the spec document.

- Analyse:
  - `README.ai.md`
  - `CONTRIBUTING.md`
  - Relevant source code
- Plan:
  - Produce a detailed plan for how to implement the feature based on the requirements.
  - The plan should focus on the "what" rather than the "how".
  - Use appropriate markdown formatting - headers, lists, tables, code blocks, etc
  - Use pseudo-code rather than actual code to highlight processes if required.
  - Update the `## Plan` section of the spec document - you MUST NOT make any other changes.
- Create Tasks:
  - Generate a detailed list of tasks and subtasks that summarise the actions needed to implement the plan.
  - The task list should be a nested list structure with markdown checkboxes `[ ]`
  - Update the `## Tasks` section of the spec document - you MUST NOT make any other changes.
- Review:
  - Present the plan to the user
  - Ask: "Does this capture your intent? Any changes needed?"
  - Iterate until the user approves - update the `## Plan` and `## Tasks` sections of the spec as required.
  - End with: "Plan looks good? Type 'GO!' when ready to proceed"

## PHASE: Execution

- Update the Current Phase in the spec document
- Proceed autonomously through the tasks defined in the `## Tasks` section of the spec document.
- For each task and subtask:
  - Refer to the `## Requirements` and `## Plan` sections of the spec document for relevant information and context.
  - If scope changes, prompt the user and update the `## Plan` section  of the spec document as appropriate.
  - Sumarise the actions you take into the `## Log` section of the spec document - each entry should be a single line.
  - Update the relevant checkboxes in the `## Tasks` section of the spec document upon completion

## Phase: Validate

- Update the Current Phase in the spec document

## Phase: Completed

- Update the Current Phase in the spec document
