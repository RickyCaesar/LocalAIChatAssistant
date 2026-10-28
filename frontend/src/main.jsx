import React from 'react';
import ReactDOM from 'react-dom/client';
import App from './App.jsx';
import FeedbackHost from './FeedbackHost.jsx';
import './index.css';
import './theme.css';

ReactDOM.createRoot(document.getElementById('root')).render(
  <React.StrictMode>
    <App />
    <FeedbackHost />
  </React.StrictMode>
);