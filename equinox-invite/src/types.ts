export interface Invitation {
  token: string;
  honorific: string | null; // "M.", "Mme", "Mlle"...
  guest_name: string;
  event_title: string;
  event_date: string; // texte affichable, ex : "Sam. 21 sept."
  event_year: string; // ex : "2026"
  event_time: string; // ex : "18 h 30"
  event_time_note: string | null; // ex : "Accueil dès 18 h"
  event_venue: string; // ex : "Domaine du Lac"
  event_venue_note: string | null; // ex : "Plan à suivre"
  message: string;
  signature: string | null; // ex : "Avec toute notre reconnaissance"
}
