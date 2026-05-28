/* eslint-disable */
import './index.less'

import PropTypes from 'ant-design-vue/es/_util/vue-types'
import { getComponentFromProp, hasProp } from 'ant-design-vue/lib/_util/props-util'

const GlobalFooterProps = {
  links: PropTypes.array,
  content: PropTypes.any
}

const classNames = ['ant', 'pro', 'global', 'footer'].join('-')

const GlobalFooter = {
  name: 'GlobalFooter',
  props: GlobalFooterProps,
  render () {
    const content = getComponentFromProp(this, 'content')
    const links = getComponentFromProp(this, 'links')
    const linksType = hasProp(links)

    return (
      <footer class={classNames}>
        <div class={classNames + '-' + 'links'}>
          {linksType && (
            links.map(link => (
              <a
                key={link.key}
                title={link.key}
                target={link.blankTarget ? '_blank' : '_self'}
                href={link.href}
              >
                {link.title}
              </a>
            ))
          ) || links}
        </div>
        {content && (
          <div class={classNames + '-' + 'content'}>{content}</div>
        )}
      </footer>
    )
  }
}

export default GlobalFooter
