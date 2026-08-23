import { StrictMode } from 'react'
import { createRoot } from 'react-dom/client'
import './index.css'
import App from './App.jsx'
import UpdateToast from './components/UpdateToast'
import { LanguageProvider } from './contexts/LanguageContext'

// UpdateToast sits outside App so the service worker registers on the login
// screen too — updates are detected before the first authenticated session.
createRoot(document.getElementById('root')).render(
  <StrictMode>
    <LanguageProvider>
      <UpdateToast />
      <App />
    </LanguageProvider>
  </StrictMode>,
)
