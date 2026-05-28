const getters = {
  platform: state => state.app.platform,
  token: state => state.user.token,
  userId: state => state.user.userId,
  appTheme: state => state.theme.appTheme,
  pageQuery: state => state.page.query
}

export default getters
