import i18n from 'i18next'
import { initReactI18next } from 'react-i18next'
import en from './en.json'
import pl from './pl.json'

const saved = localStorage.getItem('fq_lang')
const nav = navigator.language?.toLowerCase().startsWith('pl') ? 'pl' : 'en'

void i18n.use(initReactI18next).init({
  resources: {
    en: { translation: en },
    pl: { translation: pl },
  },
  lng: saved || nav,
  fallbackLng: 'en',
  interpolation: { escapeValue: false },
})

i18n.on('languageChanged', (lng) => {
  localStorage.setItem('fq_lang', lng)
  document.documentElement.lang = lng
})

document.documentElement.lang = i18n.language

export default i18n
