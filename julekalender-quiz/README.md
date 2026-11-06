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

Ved første deltagelse skal brugeren acceptere at indgå i stillingen. Efter et
svar sendes brugeren automatisk til siden **Julequiz stilling**, hvor deltagere
kan se hinandens samlede point og antal besvarede spørgsmål. Siden oprettes
automatisk af pluginet.

Samtykket gemmes pr. lokal bruger. Testknappen nulstiller svar og point, men
ikke samtykket, så samme tester kan svare igen med det samme.

## Android-app

Julekalender Quiz registreres som en PWA via Plugin Controller. Åbn den
publicerede side med `[julekalender_quiz]` på Android og vælg browserens
**Føj til startskærm**. Stillingen ligger på samme app-scope, så navigationen
til **Julequiz stilling** bliver i appen.

Plugin Controller kan også være den fælles startside. Opret én WordPress-side
med `[plugin_controller_apps]`. Herfra kan brugeren åbne Julekalender Quiz og
andre plugins, som brugeren har fået adgang til, uden en separat WordPress-side
for hvert plugin.

Eksemplet indeholder tre spørgsmål. De øvrige låger kan udvides i
`includes/class-jkq-quiz.php`.

Hver lokal bruger kan kun svare én gang på hvert dagsnummer. Svarene gemmes
separat pr. lokal bruger.

Intro-teksten i indstillingerne vises på en velkomstside før quizzen.
Hver lokal bruger kan vælge ikke at få vist introduktionen automatisk igen;
valget følger brugeren på tværs af enheder. Linket **Vis introduktion** åbner
den igen, hvor brugeren også kan slå automatisk visning til.
Hvis intro-teksten er tom, vises hverken introduktionssiden eller linket.
Nye quizindstillinger får en dansk standardintro. Eksisterende tekster bevares;
administratoren kan vælge **Gendan standardintro** på quizindstillingssiden for
at erstatte den nuværende tekst med standardteksten. Teksten kan derefter
redigeres eller tømmes.
