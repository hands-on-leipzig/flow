# Volunteer Input UI Proposals

## Requirements
- Input: name, role, program (E/C/empty)
- Volume: 10-50+ volunteers
- Program mapping: E = Explore logo, C = Challenge logo, other/empty = no logo

## Proposal 1: Dynamic Table with CSV Import (Recommended)
**Best for: Most users, flexible input**

### Features:
- **Table view** with add/remove rows
- **CSV paste** - paste tab-separated or comma-separated data
- **Excel upload** - upload Excel file
- **Excel download** - download template
- **Inline editing** - edit directly in table
- **Bulk operations** - add multiple rows at once

### UI Structure:
```
┌─────────────────────────────────────────────────┐
│ Namensaufkleber für Volunteers                  │
│                                                  │
│ [Vorlage Excel herunterladen] [CSV einfügen]    │
│                                                  │
│ ┌───────────────────────────────────────────┐ │
│ │ Name        │ Rolle      │ Programm │ [×] │ │
│ ├───────────────────────────────────────────┤ │
│ │ Max Mustermann │ Schiedsrichter │ E    │ [×] │ │
│ │ Anna Schmidt   │ Zeitnehmer    │ C    │ [×] │ │
│ │ ...            │ ...           │ ...  │ [×] │ │
│ └───────────────────────────────────────────┘ │
│                                                  │
│ [+ Zeile hinzufügen]  [PDF generieren]          │
└─────────────────────────────────────────────────┘
```

### Advantages:
- ✅ Visual table - easy to see all data
- ✅ Quick editing - inline editing
- ✅ Bulk input - CSV paste for many entries
- ✅ Excel support - familiar format
- ✅ Add/remove rows - flexible

---

## Proposal 2: Textarea with Live Preview
**Best for: Quick bulk input**

### Features:
- **Textarea** - paste tab/CSV data
- **Live preview table** - see parsed data
- **Format hints** - show expected format
- **Excel upload** - alternative input

### UI Structure:
```
┌─────────────────────────────────────────────────┐
│ Namensaufkleber für Volunteers                  │
│                                                  │
│ Format: Name<TAB>Rolle<TAB>Programm (E/C)      │
│                                                  │
│ ┌───────────────────────────────────────────┐ │
│ │ Max Mustermann    Schiedsrichter    E      │ │
│ │ Anna Schmidt      Zeitnehmer        C      │ │
│ │ ...                                       │ │
│ └───────────────────────────────────────────┘ │
│                                                  │
│ Vorschau (3 Einträge):                         │
│ • Max Mustermann - Schiedsrichter (Explore)    │
│ • Anna Schmidt - Zeitnehmer (Challenge)        │
│                                                  │
│ [Excel hochladen] [PDF generieren]              │
└─────────────────────────────────────────────────┘
```

### Advantages:
- ✅ Fast bulk input - paste entire list
- ✅ Simple - no table management
- ✅ Excel upload - for complex data

### Disadvantages:
- ❌ Less visual - harder to edit individual entries
- ❌ Format sensitive - must match exactly

---

## Proposal 3: Excel Upload Only
**Best for: Users comfortable with Excel**

### Features:
- **Excel template download** - pre-formatted
- **Excel upload** - upload filled template
- **Validation** - show errors before PDF generation
- **Preview** - show parsed data before generating

### UI Structure:
```
┌─────────────────────────────────────────────────┐
│ Namensaufkleber für Volunteers                  │
│                                                  │
│ 1. [Vorlage Excel herunterladen]                │
│ 2. Excel-Datei ausfüllen                        │
│ 3. [Ausgefülltes Excel hochladen]               │
│                                                  │
│ Vorschau (nach Upload):                         │
│ • 15 Volunteers gefunden                        │
│ • 8 Explore, 5 Challenge, 2 ohne Programm      │
│                                                  │
│ [PDF generieren]                                │
└─────────────────────────────────────────────────┘
```

### Advantages:
- ✅ Familiar - Excel is widely used
- ✅ Powerful - Excel features (sorting, formulas)
- ✅ Clean UI - minimal interface

### Disadvantages:
- ❌ Requires Excel - not everyone has it
- ❌ Extra step - download, fill, upload

---

## Recommendation: **Proposal 1 - Dynamic Table with CSV Import**

### Why:
1. **Flexibility** - supports both quick bulk input (CSV) and careful editing (table)
2. **User-friendly** - visual table is easy to understand
3. **Efficient** - paste CSV for bulk, edit table for corrections
4. **Familiar** - similar to existing admin tables in the codebase

### Implementation Details:

**Table Columns:**
- Name (text input)
- Role (text input) 
- Program (dropdown: E, C, - (kein Logo))

**Actions:**
- Add row button
- Remove row button per row
- CSV paste button (opens modal/textarea)
- Excel upload button
- Excel template download button
- Generate PDF button

**CSV Format:**
```
Name<TAB>Rolle<TAB>Programm
Max Mustermann<TAB>Schiedsrichter<TAB>E
Anna Schmidt<TAB>Zeitnehmer<TAB>C
```

**Excel Format:**
- Column A: Name
- Column B: Role
- Column C: Program (E, C, or empty)

---

## Alternative: Hybrid Approach
Combine Proposal 1 + 2:
- Default: Table view
- Toggle: Switch to "Bulk Input" mode (textarea)
- Both modes share same data state
