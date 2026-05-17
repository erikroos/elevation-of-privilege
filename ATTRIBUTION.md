# Attribution

This implementation re-uses content from two third-party card games.
Each card displayed in the application includes a short attribution line
that points back to its original source.

## 1. Elevation of Privilege (STRIDE deck)

- **Authors**: Adam Shostack, Microsoft Corporation
- **Original**: <https://www.microsoft.com/en-us/download/details.aspx?id=20303>
- **Reference site**: <https://shostack.org/games/elevation-of-privilege>
- **License**: Creative Commons Attribution 3.0 (CC BY 3.0)
  <https://creativecommons.org/licenses/by/3.0/>
- **Changes from the original**:
  - Card text is stored as JSON and rendered as HTML cards (text +
    category icon) rather than the original printed card art.
  - User interface is translated to Dutch; card text remains in English
    (original wording).

## 2. LINDDUN GO (LINDDUN deck)

- **Authors**: DistriNet research group, KU Leuven
  (Kim Wuyts, Laurens Sion, Wouter Joosen, et al.)
- **Original**: <https://linddun.org/go/>
- **License**: Creative Commons Attribution 4.0 International
  (CC BY 4.0)
  <https://creativecommons.org/licenses/by/4.0/>
- **Version**: LINDDUN GO 2024 (v241203). 33 threat cards across the
  seven LINDDUN threat types.
- **Changes from the original**:
  - Cards are mapped onto a 7-suit / ranked structure compatible with
    the Elevation of Privilege game loop, so the same engine can drive
    either deck. Card identifiers (L1, I1, Nr1, …) are preserved in the
    `title` field; ranks are assigned sequentially within each suit.
  - Only the card titles and short descriptions are reproduced; the
    elicitation questions, examples, and consequences from the printed
    deck are not included.
  - User interface is translated to Dutch.

## 3. This implementation

- **Author**: Erik Roos, 2026
- **License**: CC BY 4.0 (see `LICENSE`)
- **Source**: <https://github.com/erikroos/elevation-of-privilege/>

If you fork or re-host this project, you MUST keep the attributions in
`ATTRIBUTION.md`, the per-card credit line in the UI, and the
`/about` page intact.
