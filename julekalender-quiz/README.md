# Julekalender Quiz

Et eksempel på en daglig julequiz, der bruger **Plugin Controller** til sin
administrationsside.

## Brug

1. Aktivér `Plugin Controller` og `Julekalender Quiz`.
2. Gå til **Plugin Controller → Local Access**, opret en lokal bruger, og giv
   brugeren adgang til **Julekalender Quiz**.
3. Indsæt `[julekalender_quiz]` på en side. Brugeren skal logge ind via den
   fælles Local Access-formular, før quizzen vises.
4. Gå til **Plugin Controller → Julekalender Quiz** og vælg eventuelt
   **Dag 1**, så eksemplet kan vises uden for december.
5. Når du tester igen, kan du gå til **Plugin Controller → Julekalender Quiz**
   og klikke på **Nulstil alle svar**. Alle gemte svar nulstilles.

Plugin Controller kan også være den fælles startside. Opret én WordPress-side
med `[plugin_controller_apps]`. Herfra kan brugeren åbne Julekalender Quiz og
andre plugins, som brugeren har fået adgang til, uden en separat WordPress-side
for hvert plugin.

Eksemplet indeholder tre spørgsmål. De øvrige låger kan udvides i
`includes/class-jkq-quiz.php`.

Hver lokal bruger kan kun svare én gang på hvert dagsnummer. Svarene gemmes
separat pr. lokal bruger.
